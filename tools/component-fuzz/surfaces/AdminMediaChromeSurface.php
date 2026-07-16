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
			$rows[] = self::check_legacy_upload_shell_helpers( $ctx->fork( 'legacy-upload-shell' ) );
			$rows[] = self::check_media_upload_dispatch_exits( $ctx->fork( 'legacy-upload-dispatch' ) );
			$rows[] = self::check_media_url_insert_dispatch_exits( $ctx->fork( 'legacy-url-insert' ) );
			$rows[] = self::check_media_gallery_save_iframe_dispatch( $ctx->fork( 'legacy-gallery-save' ) );
			$rows[] = self::check_media_type_iframe_dispatch( $ctx->fork( 'legacy-type-iframe' ) );
			$rows[] = self::check_media_upload_entry_dispatch( $ctx->fork( 'legacy-entry-dispatch' ) );
			$rows[] = self::check_media_library_gallery_iframe_rendering( $ctx->fork( 'legacy-library-gallery' ) );
			$rows[] = self::check_media_library_query_date_filters( $ctx->fork( 'legacy-library-query-filters' ) );
			$rows[] = self::check_media_library_query_date_stub_edges( $ctx->fork( 'legacy-library-date-stub-edges' ) );
			$rows[] = self::check_media_library_query_alias_defaults( $ctx->fork( 'legacy-library-query-aliases' ) );
			$rows[] = self::check_media_attach_action_redirect_exit( $ctx->fork( 'media-attach-action' ) );
			$rows[] = self::check_media_enqueue_and_iframe_shell( $ctx->fork( 'modal-enqueue-shell' ) );
			$rows[] = self::check_media_button_and_bypass_output( $ctx->fork( 'media-buttons' ) );
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
				'add_query_arg',
				'_device_can_upload',
				'_wp_admin_html_begin',
				'admin_url',
				'clean_attachment_cache',
				'create_initial_post_types',
				'create_initial_taxonomies',
				'current_user_can',
				'edit_form_image_editor',
				'get_attachment_link',
				'get_attachment_fields_to_edit',
				'get_compat_media_markup',
				'get_image_send_to_editor',
				'get_image_tag',
				'get_media_item',
				'get_media_items',
				'get_submit_button',
				'get_user_option',
				'get_user_setting',
				'image_align_input_fields',
				'image_add_caption',
				'image_edit_apply_changes',
				'image_link_input_fields',
				'image_media_send_to_editor',
				'image_size_input_fields',
				'media_send_to_editor',
				'media_buttons',
				'media_upload_form',
				'media_upload_flash_bypass',
				'media_upload_form_handler',
				'media_upload_gallery',
				'media_upload_gallery_form',
				'media_upload_header',
				'media_upload_html_bypass',
				'media_upload_library',
				'media_upload_library_form',
				'media_upload_tabs',
				'media_upload_type_form',
				'media_upload_type_url_form',
				'paginate_links',
				'_get_list_table',
				'remove_query_arg',
				'sanitize_html_class',
				'size_format',
				'the_media_upload_tabs',
				'update_gallery_tab',
				'wp_count_attachments',
				'wp_edit_attachments_query',
				'wp_edit_attachments_query_vars',
				'wp_match_mime_types',
				'wp_get_attachment_image',
				'wp_get_attachment_image_src',
				'wp_get_attachment_metadata',
				'wp_get_attachment_thumb_url',
				'wp_get_referer',
				'wp_create_nonce',
				'wp_die',
				'wp_enqueue_media',
				'wp_enqueue_script',
				'wp_enqueue_style',
				'wp_image_editor',
				'wp_iframe',
				'wp_insert_post',
				'wp_is_mobile',
				'wp_json_encode',
				'wp_max_upload_size',
				'wp_media_attach_action',
				'wp_media_upload_handler',
				'wp_media_insert_url_form',
				'wp_mime_type_icon',
				'wp_editor',
				'wp_ext2type',
				'wp_register_script',
				'wp_register_style',
				'wp_redirect',
				'wp_script_is',
				'wp_scripts',
				'wp_set_current_user',
				'wp_style_is',
				'wp_styles',
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

	private static function check_legacy_upload_shell_helpers( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$snapshot        = self::snapshot_state();
		$token           = $ctx->identifier( 3, 8 );
		$post_id         = self::seed_parent_post( $ctx->fork( 'post' ) );
		$unsafe_type     = 'image-' . $token . '</script><script>alert(1)</script>';
		$unsafe_tab      = 'type-' . $token . '</script><script>alert(2)</script>';
		$unsafe_post_id  = (string) $post_id . '<script>alert(3)</script>';
		$action_counts   = array();
		$tab_events      = array();
		$form_url_events = array();
		$post_events     = array();
		$plupload_events = array();
		$direct_tabs     = array();
		$header_html     = '';
		$chromeless_html = '';
		$type_form_html  = '';

		$tabs_filter = static function ( array $tabs ) use ( &$tab_events, $token ): array {
			$tab_events[] = array_keys( $tabs );
			$tabs['component_fuzz'] = 'Component Fuzz ' . $token;
			return $tabs;
		};
		$form_url_filter = static function ( string $url, string $type ) use ( &$form_url_events, $token ): string {
			$form_url_events[] = array(
				'url'  => $url,
				'type' => $type,
			);
			return \add_query_arg( 'cfz_upload_marker', $token, $url );
		};
		$post_params_filter = static function ( array $params ) use ( &$post_events, $token ): array {
			$post_events[] = $params;
			$params['component_fuzz_param'] = $token;
			return $params;
		};
		$plupload_filter = static function ( array $init ) use ( &$plupload_events, $token ): array {
			$plupload_events[] = $init;
			$init['component_fuzz_init'] = $token;
			return $init;
		};
		$tracked_actions = array(
			'pre-upload-ui',
			'pre-plupload-upload-ui',
			'post-plupload-upload-ui',
			'pre-html-upload-ui',
			'post-html-upload-ui',
			'post-upload-ui',
		);
		$action_callbacks = array();
		foreach ( $tracked_actions as $hook ) {
			$action_counts[ $hook ]   = 0;
			$action_callbacks[ $hook ] = static function () use ( &$action_counts, $hook ): void {
				++$action_counts[ $hook ];
			};
		}

		\add_filter( 'media_upload_tabs', $tabs_filter );
		\add_filter( 'media_upload_form_url', $form_url_filter, 10, 2 );
		\add_filter( 'upload_post_params', $post_params_filter );
		\add_filter( 'plupload_init', $plupload_filter );
		foreach ( $action_callbacks as $hook => $callback ) {
			\add_action( $hook, $callback );
		}

		try {
			$GLOBALS['type'] = $unsafe_type;
			$GLOBALS['tab']  = $unsafe_tab;
			$_SERVER['HTTP_USER_AGENT'] = 'ComponentFuzz Desktop';
			$_GET     = array(
				'tab' => 'component_fuzz',
			);
			$_POST    = array();
			$_REQUEST = array(
				'post_id' => $unsafe_post_id,
			);

			$direct_tabs = \media_upload_tabs();
			$header_html = self::capture_output(
				static function (): void {
					\media_upload_header();
				}
			);

			$_GET['chromeless'] = '1';
			$chromeless_html    = self::capture_output(
				static function (): void {
					\media_upload_header();
				}
			);

			unset( $_GET['chromeless'] );
			$type_form_html = self::capture_output(
				static function (): void {
					\media_upload_type_form( 'image', null, null );
				}
			);
		} finally {
			\remove_filter( 'media_upload_tabs', $tabs_filter );
			\remove_filter( 'media_upload_form_url', $form_url_filter, 10 );
			\remove_filter( 'upload_post_params', $post_params_filter );
			\remove_filter( 'plupload_init', $plupload_filter );
			foreach ( $action_callbacks as $hook => $callback ) {
				\remove_action( $hook, $callback );
			}
			self::restore_state( $snapshot );
		}

		self::collect_failure(
			$failures,
			isset( $direct_tabs['type'], $direct_tabs['type_url'], $direct_tabs['gallery'], $direct_tabs['library'], $direct_tabs['component_fuzz'] )
				&& 'Component Fuzz ' . $token === $direct_tabs['component_fuzz'],
			'media_upload_tabs() exposes default legacy tabs and scoped filter additions',
			array( 'directTabs' => $direct_tabs )
		);

		self::collect_failure(
			$failures,
			str_contains( $header_html, '<script>post_id = ' . $post_id . ';</script>' )
				&& str_contains( $header_html, '<div id="media-upload-header">' )
				&& str_contains( $header_html, "id='tab-component_fuzz'" )
				&& str_contains( $header_html, "class='current'" )
				&& str_contains( $chromeless_html, '<script>post_id = ' . $post_id . ';</script>' )
				&& ! str_contains( $chromeless_html, '<div id="media-upload-header">' )
				&& ! str_contains( $header_html . $chromeless_html, $unsafe_post_id ),
			'media_upload_header() casts request post IDs and honors chromeless legacy tab rendering',
			array(
				'header'     => self::describe_string( $header_html ),
				'chromeless' => self::describe_string( $chromeless_html ),
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $type_form_html, 'enctype="multipart/form-data"' )
				&& str_contains( $type_form_html, 'id="image-form"' )
				&& str_contains( $type_form_html, 'name="_wpnonce"' )
				&& str_contains( $type_form_html, 'id="post_id" value="' . $post_id . '"' )
				&& str_contains( $type_form_html, 'cfz_upload_marker=' . $token )
				&& str_contains( $type_form_html, 'wpUploaderInit = ' )
				&& str_contains( $type_form_html, 'component_fuzz_param' )
				&& str_contains( $type_form_html, 'component_fuzz_init' )
				&& str_contains( $type_form_html, '\\u003C/script\\u003E' )
				&& ! str_contains( $type_form_html, $unsafe_type )
				&& ! str_contains( $type_form_html, $unsafe_tab )
				&& ! str_contains( $type_form_html, $unsafe_post_id ),
			'media_upload_type_form() renders the non-dispatch upload shell with nonce, filtered action URL, and JSON-escaped uploader settings',
			array( 'html' => self::describe_string( $type_form_html ) )
		);

		self::collect_failure(
			$failures,
			1 === count( $form_url_events )
				&& 'image' === ( $form_url_events[0]['type'] ?? null )
				&& 1 === count( $post_events )
				&& $post_id === (int) ( $post_events[0]['post_id'] ?? 0 )
				&& $unsafe_type === ( $post_events[0]['type'] ?? null )
				&& $unsafe_tab === ( $post_events[0]['tab'] ?? null )
				&& 1 === count( $plupload_events )
				&& $token === ( $plupload_events[0]['multipart_params']['component_fuzz_param'] ?? null )
				&& array() === array_filter(
					$action_counts,
					static function ( int $count ): bool {
						return 1 !== $count;
					}
				),
			'legacy upload shell fires documented form filters and upload UI hooks exactly once without dispatching uploads',
			array(
				'formUrlEvents'  => $form_url_events,
				'postEvents'     => $post_events,
				'pluploadEvents' => $plupload_events,
				'actionCounts'   => $action_counts,
				'tabEvents'      => $tab_events,
			)
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'media_upload_tabs', $tabs_filter )
				&& false === \has_filter( 'media_upload_form_url', $form_url_filter )
				&& false === \has_filter( 'upload_post_params', $post_params_filter )
				&& false === \has_filter( 'plupload_init', $plupload_filter ),
			'legacy upload shell filters are scoped to the invariant',
			array()
		);

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-upload-shell-server-output',
			array() === $failures,
			array(
				'failures'   => $failures,
				'notClaimed' => array(
					'SAPI-marked successful browser uploads through wp_media_upload_handler(); direct no-network media_handle_upload()/media_handle_sideload() ingest is owned by media-ingest.',
				),
			)
		);
	}

	private static function check_media_enqueue_and_iframe_shell( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures        = array();
		$snapshot        = self::snapshot_state();
		$token           = $ctx->identifier( 3, 8 );
		$post_id         = self::seed_parent_post( $ctx->fork( 'post' ) );
		$iframe_payload  = 'iframe <script>alert(1)</script> ' . $token;
		$settings_events = array();
		$strings_events  = array();
		$filter_events   = array();
		$enqueue_actions = 0;
		$localized       = '';
		$script_status   = array();
		$style_status    = array();
		$template_hooks  = array();
		$did_enqueue     = 0;
		$iframe_html     = '';

		$tabs_filter = static function ( array $tabs ) use ( &$filter_events, $token ): array {
			$filter_events['tabs'][] = array_keys( $tabs );
			$tabs['component_fuzz_modal'] = 'Component Fuzz Modal ' . $token;
			return $tabs;
		};
		$audio_filter = static function ( $show ) use ( &$filter_events ): bool {
			$filter_events['audio'][] = $show;
			return false;
		};
		$video_filter = static function ( $show ) use ( &$filter_events ): bool {
			$filter_events['video'][] = $show;
			return true;
		};
		$months_filter = static function ( $months ) use ( &$filter_events ): array {
			$filter_events['months'][] = $months;
			return array(
				(object) array(
					'month' => 6,
					'year'  => 2026,
				),
			);
		};
		$infinite_filter = static function ( bool $infinite ) use ( &$filter_events ): bool {
			$filter_events['infinite'][] = $infinite;
			return true;
		};
		$captions_filter = static function ( $disabled ) use ( &$filter_events ): bool {
			$filter_events['captions'][] = $disabled;
			return true;
		};
		$settings_filter = static function ( array $settings, $post ) use ( &$settings_events, $token ): array {
			$settings['componentFuzzSetting'] = $token;
			$settings_events[] = array(
				'postId'   => $post instanceof \WP_Post ? $post->ID : null,
				'settings' => $settings,
			);
			return $settings;
		};
		$strings_filter = static function ( array $strings, $post ) use ( &$strings_events, $token ): array {
			$strings['componentFuzzString'] = $token;
			$strings_events[] = array(
				'postId'  => $post instanceof \WP_Post ? $post->ID : null,
				'strings' => $strings,
			);
			return $strings;
		};
		$enqueue_action = static function () use ( &$enqueue_actions ): void {
			++$enqueue_actions;
		};

		\add_filter( 'media_upload_tabs', $tabs_filter );
		\add_filter( 'media_library_show_audio_playlist', $audio_filter );
		\add_filter( 'media_library_show_video_playlist', $video_filter );
		\add_filter( 'media_library_months_with_files', $months_filter );
		\add_filter( 'media_library_infinite_scrolling', $infinite_filter );
		\add_filter( 'disable_captions', $captions_filter );
		\add_filter( 'media_view_settings', $settings_filter, 10, 2 );
		\add_filter( 'media_view_strings', $strings_filter, 10, 2 );
		\add_action( 'wp_enqueue_media', $enqueue_action );

		try {
			$GLOBALS['content_width'] = 733;
			$GLOBALS['body_id']       = 'component-fuzz-iframe';

			\wp_enqueue_media( array( 'post' => $post_id ) );
			$localized = (string) \wp_scripts()->get_data( 'media-views', 'data' );
			$script_status = array(
				'media-editor'     => \wp_script_is( 'media-editor', 'enqueued' ),
				'media-audiovideo' => \wp_script_is( 'media-audiovideo', 'enqueued' ),
			);
			$style_status = array(
				'media-views'    => \wp_style_is( 'media-views', 'enqueued' ),
				'imgareaselect'  => \wp_style_is( 'imgareaselect', 'enqueued' ),
			);
			$template_hooks = array(
				'admin_footer'                               => \has_action( 'admin_footer', 'wp_print_media_templates' ),
				'wp_footer'                                  => \has_action( 'wp_footer', 'wp_print_media_templates' ),
				'customize_controls_print_footer_scripts'    => \has_action( 'customize_controls_print_footer_scripts', 'wp_print_media_templates' ),
			);
			$did_enqueue = \did_action( 'wp_enqueue_media' );

			$iframe_html = self::capture_output(
				static function () use ( $iframe_payload ): void {
					\wp_iframe( 'esc_html_e', $iframe_payload );
				}
			);
		} finally {
			\remove_filter( 'media_upload_tabs', $tabs_filter );
			\remove_filter( 'media_library_show_audio_playlist', $audio_filter );
			\remove_filter( 'media_library_show_video_playlist', $video_filter );
			\remove_filter( 'media_library_months_with_files', $months_filter );
			\remove_filter( 'media_library_infinite_scrolling', $infinite_filter );
			\remove_filter( 'disable_captions', $captions_filter );
			\remove_filter( 'media_view_settings', $settings_filter, 10 );
			\remove_filter( 'media_view_strings', $strings_filter, 10 );
			\remove_action( 'wp_enqueue_media', $enqueue_action );
			self::restore_state( $snapshot );
		}

		$settings = $settings_events[0]['settings'] ?? array();
		$strings  = $strings_events[0]['strings'] ?? array();
		$month    = $settings['months'][0] ?? null;

		self::collect_failure(
			$failures,
			1 === count( $settings_events )
				&& $post_id === (int) ( $settings_events[0]['postId'] ?? 0 )
				&& isset( $settings['tabs']['component_fuzz_modal'] )
				&& 'Component Fuzz Modal ' . $token === $settings['tabs']['component_fuzz_modal']
				&& false === ( $settings['captions'] ?? null )
				&& 0 === (int) ( $settings['attachmentCounts']['audio'] ?? -1 )
				&& 1 === (int) ( $settings['attachmentCounts']['video'] ?? -1 )
				&& 1 === (int) ( $settings['infiniteScrolling'] ?? 0 )
				&& 733 === (int) ( $settings['contentWidth'] ?? 0 )
				&& $month instanceof \stdClass
				&& 6 === (int) $month->month
				&& 2026 === (int) $month->year
				&& isset( $month->text ),
			'wp_enqueue_media() builds filterable media-view settings without querying media months or playlist counts',
			array(
				'settingsEvents' => $settings_events,
				'filterEvents'   => $filter_events,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $strings_events )
				&& $post_id === (int) ( $strings_events[0]['postId'] ?? 0 )
				&& isset( $strings['componentFuzzString'] )
				&& $token === $strings['componentFuzzString'],
			'wp_enqueue_media() filters media strings before attaching the filtered settings payload for localization',
			array( 'stringsEvents' => $strings_events )
		);

		self::collect_failure(
			$failures,
			'' === $localized
				|| (
					str_contains( $localized, '_wpMediaViewsL10n' )
					&& str_contains( $localized, 'componentFuzzSetting' )
					&& str_contains( $localized, 'componentFuzzString' )
					&& str_contains( $localized, $token )
				),
			'wp_enqueue_media() localizes filtered media strings when the media-views handle is registered',
			array(
				'localized' => self::describe_string( $localized ),
				'scripts'   => $script_status,
				'styles'    => $style_status,
			)
		);

		self::collect_failure(
			$failures,
			1 === $did_enqueue
				&& 1 === $enqueue_actions
				&& ! in_array( false, $template_hooks, true ),
			'wp_enqueue_media() completes its action and registers media templates once',
			array(
				'templateHooks' => $template_hooks,
				'didAction'     => $did_enqueue,
				'actionCount'   => $enqueue_actions,
			)
		);

		self::collect_failure(
			$failures,
			str_contains( $iframe_html, 'pagenow = \'media-upload-popup\'' )
				&& str_contains( $iframe_html, '<body id="component-fuzz-iframe" class="wp-core-ui no-js ' )
				&& str_contains( $iframe_html, \esc_html( $iframe_payload ) )
				&& ! str_contains( $iframe_html, $iframe_payload )
				&& str_contains( $iframe_html, '</html>' ),
			'wp_iframe() renders the legacy media iframe shell and escapes callback output in-process',
			array( 'iframe' => self::describe_string( $iframe_html ) )
		);

		self::collect_failure(
			$failures,
			false === \has_filter( 'media_upload_tabs', $tabs_filter )
				&& false === \has_filter( 'media_view_settings', $settings_filter )
				&& false === \has_filter( 'media_view_strings', $strings_filter )
				&& false === \has_action( 'wp_enqueue_media', $enqueue_action ),
			'media enqueue and iframe filters/actions are scoped to the invariant',
			array()
		);

		return self::row(
			$ctx,
			'admin-media-chrome.modal-enqueue-data-and-iframe-shell',
			array() === $failures,
			array(
				'failures'   => $failures,
				'notClaimed' => array(
					'Backbone media modal JavaScript runtime behavior after enqueue',
					'default admin script/style registration and localization when media handles are unavailable in the no-admin bootstrap',
					'admin-ajax.php image editor dispatch actions',
					'Thickbox browser interactions after the wp_iframe() shell is rendered',
					'full legacy gallery screen submission flows',
				),
			)
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

	private static function check_media_upload_dispatch_exits( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-upload-dispatch-exits',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_upload_dispatch_cases( $ctx ) as $case ) {
			$run    = self::run_media_upload_dispatch_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'       => $result['returned'] ?? null,
					'output'         => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'dieCalls'       => $result['dieCalls'] ?? array(),
					'saveEventCount' => is_array( $result['saveEvents'] ?? null ) ? count( $result['saveEvents'] ) : null,
					'sendEventCount' => is_array( $result['sendEvents'] ?? null ) ? count( $result['sendEvents'] ) : null,
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_upload_dispatch_child_result_has_expected_shape( $result ),
				"{$case['label']} child exits cleanly and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_upload_dispatch_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				false === (bool) ( $result['returned'] ?? true )
					&& null === ( $result['throwable'] ?? null )
					&& array() === ( $result['dieCalls'] ?? array() ),
				"{$case['label']} reaches the intended legacy exit without wp_die or unexpected exceptions",
				array(
					'returned'  => $result['returned'] ?? null,
					'throwable' => $result['throwable'] ?? null,
					'dieCalls'  => $result['dieCalls'] ?? array(),
				)
			);

			if ( 'send' === $case['scenario'] ) {
				self::collect_media_upload_send_failures( $failures, $case, $result );
			} elseif ( 'insert-gallery' === $case['scenario'] ) {
				self::collect_media_upload_gallery_exit_failures( $failures, $case, $result );
			} else {
				self::collect_media_upload_type_error_failures( $failures, $case, $result );
			}
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-upload-dispatch-exits',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_upload_dispatch_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, string $scenario, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$token = self::media_upload_dispatch_token( 'upload_' . $case_ctx->identifier( 4, 9 ) );

			return array(
				'label'     => $label,
				'scenario'  => $scenario,
				'seed'      => $case_ctx->seed(),
				'iteration' => $case_ctx->iteration(),
				'token'     => $token,
			);
		};

		return array(
			$build( 'send-to-editor', 'send', $ctx->fork( 'send' ) ),
			$build( 'insert-gallery', 'insert-gallery', $ctx->fork( 'insert-gallery' ) ),
			$build( 'type-form-error', 'type-error', $ctx->fork( 'type-error' ) ),
		);
	}

	private static function media_upload_dispatch_token( string $token ): string {
		$safe = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
		if ( ! is_string( $safe ) || '' === $safe ) {
			return 'upload_token';
		}

		return $safe;
	}

	private static function run_media_upload_dispatch_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates legacy media upload send/gallery/error exit paths in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_upload_dispatch_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_upload_dispatch_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_upload_dispatch_child( $case );
PHP;
	}

	public static function run_media_upload_dispatch_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx      = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$scenario = (string) ( $case['scenario'] ?? 'send' );
		$token    = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state    = array(
			'ok'             => false,
			'label'          => (string) ( $case['label'] ?? 'upload-dispatch' ),
			'scenario'       => $scenario,
			'token'          => $token,
			'parentId'       => 0,
			'newParentId'    => 0,
			'allowedId'      => 0,
			'deniedId'       => 0,
			'allTrackedIds'  => array(),
			'postsBefore'    => array(),
			'postsAfter'     => array(),
			'metaBefore'     => array(),
			'metaAfter'      => array(),
			'queryDelta'     => array(),
			'saveEvents'     => array(),
			'sendEvents'     => array(),
			'capEvents'      => array(),
			'dieCalls'       => array(),
			'returned'       => false,
			'throwable'      => null,
			'output'         => '',
			'errorMessage'   => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']     = $output;
				$state['postsAfter'] = self::media_upload_dispatch_post_state( array_map( 'intval', $state['allTrackedIds'] ) );
				$state['metaAfter']  = self::media_upload_dispatch_alt_state( array_map( 'intval', $state['allTrackedIds'] ) );

				$wpdb = $GLOBALS['wpdb'] ?? null;
				if ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ) {
					$queries             = $wpdb->component_fuzz_get_queries();
					$state['queryDelta'] = array_values( array_slice( $queries, (int) ( $state['queryCountBefore'] ?? 0 ) ) );
				}

				$state['ok'] = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$parent_id    = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$new_parent   = self::seed_parent_post( $ctx->fork( 'new-parent' ) );
			$allowed      = self::seed_attachment( $ctx->fork( 'allowed' ), 'image/jpeg', array( 'parent_id' => $parent_id ) );
			$denied       = self::seed_attachment( $ctx->fork( 'denied' ), 'image/png', array( 'parent_id' => $parent_id ) );
			$allowed_id   = (int) $allowed->ID;
			$denied_id    = (int) $denied->ID;
			$tracked_ids  = array( $parent_id, $new_parent, $allowed_id, $denied_id );
			$allowed_caps = array_fill_keys( array( $allowed_id ), true );

			$state['parentId']      = $parent_id;
			$state['newParentId']   = $new_parent;
			$state['allowedId']     = $allowed_id;
			$state['deniedId']      = $denied_id;
			$state['allTrackedIds'] = $tracked_ids;
			$state['postsBefore']   = self::media_upload_dispatch_post_state( $tracked_ids );
			$state['metaBefore']    = self::media_upload_dispatch_alt_state( $tracked_ids );

			$GLOBALS['pagenow']         = 'media-upload.php';
			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'POST';
			$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?type=image&tab=type';
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=image&tab=type';
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-upload-dispatch';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.43';
			$_SERVER['SERVER_PORT']     = '80';

			$map_meta_cap_filter = static function ( array $caps, string $cap, int $user_id, array $args ) use ( &$state, $allowed_caps ): array {
				if ( 'edit_post' !== $cap || ! isset( $args[0] ) ) {
					return $caps;
				}

				$post_id = (int) $args[0];
				$allowed = isset( $allowed_caps[ $post_id ] );
				$state['capEvents'][] = array(
					'postId'  => $post_id,
					'allowed' => $allowed,
				);

				return $allowed ? array( 'exist' ) : array( 'do_not_allow' );
			};
			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$die_handler_filter  = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};
			$save_filter         = static function ( array $post, array $attachment ) use ( &$state, $token, $allowed_id ): array {
				$state['saveEvents'][] = array(
					'id'         => (int) ( $post['ID'] ?? 0 ),
					'title'      => (string) ( $attachment['post_title'] ?? '' ),
					'hasAlt'     => array_key_exists( 'image_alt', $attachment ),
					'postParent' => (int) ( $attachment['post_parent'] ?? 0 ),
				);

				if ( $allowed_id === (int) ( $post['ID'] ?? 0 ) ) {
					$post['post_content'] = (string) ( $post['post_content'] ?? '' ) . ' filtered-' . $token;
				}

				return $post;
			};
			$send_filter         = static function ( string $html, int $send_id, array $attachment ) use ( &$state, $token ): string {
				$state['sendEvents'][] = array(
					'id'       => $send_id,
					'html'     => $html,
					'title'    => (string) ( $attachment['post_title'] ?? '' ),
					'url'      => (string) ( $attachment['url'] ?? '' ),
					'hasRel'   => str_contains( $html, "rel='attachment wp-att-" . $send_id . "'" ),
				);

				return $html . '<span data-cfz-upload="' . esc_attr( $token ) . '">filtered</span>';
			};

			\add_filter( 'map_meta_cap', $map_meta_cap_filter, 10, 4 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
			\add_filter( 'attachment_fields_to_save', $save_filter, 10, 2 );
			\add_filter( 'media_send_to_editor', $send_filter, 10, 3 );

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			$nonce = \wp_create_nonce( 'media-form' );
			$wpdb  = $GLOBALS['wpdb'] ?? null;
			if ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ) {
				$wpdb->rows_affected       = 0;
				$state['queryCountBefore'] = count( $wpdb->component_fuzz_get_queries() );
			}

			if ( 'type-error' === $scenario ) {
				$error_message          = 'Upload failed <script>alert(1)</script> ' . $token;
				$state['errorMessage'] = $error_message;
				$_GET                  = array();
				$_POST                 = array();
				$_REQUEST              = array( 'post_id' => (string) $parent_id );
				$_COOKIE               = array();

				\media_upload_type_form( 'image', null, new \WP_Error( 'component_fuzz_upload', $error_message ) );
				$state['returned'] = true;
				return;
			}

			$_GET     = array();
			$_POST    = array(
				'_wpnonce' => $nonce,
			);
			$_COOKIE  = array();

			if ( 'insert-gallery' === $scenario ) {
				$_POST['insert-gallery'] = '1';
				$_REQUEST                = $_POST;
				\media_upload_form_handler();
				$state['returned'] = true;
				return;
			}

			$allowed_title = 'Dispatch title ' . $token;
			$denied_title  = 'Denied title ' . $token;
			$_POST['send'] = array( $allowed_id => 'Send' );
			$_POST['attachments'] = array(
				$allowed_id => array(
					'post_title'   => $allowed_title,
					'post_content' => 'Dispatch content ' . $token,
					'post_excerpt' => 'Dispatch excerpt ' . $token,
					'menu_order'   => '7',
					'post_parent'  => (string) $new_parent,
					'image_alt'    => '<b>Alt ' . $token . '</b>',
					'url'          => 'http://example.test/?attachment_id=' . $allowed_id . '&cfz=' . rawurlencode( $token ),
				),
				$denied_id  => array(
					'post_title'   => $denied_title,
					'post_content' => 'Denied content ' . $token,
					'post_excerpt' => 'Denied excerpt ' . $token,
					'menu_order'   => '9',
					'post_parent'  => (string) $new_parent,
					'image_alt'    => 'Denied alt ' . $token,
					'url'          => 'http://example.test/denied-' . rawurlencode( $token ),
				),
			);
			$_REQUEST = $_POST;

			\media_upload_form_handler();
			$state['returned'] = true;
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_upload_dispatch_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['postsBefore'] ?? null )
			&& is_array( $result['postsAfter'] ?? null )
			&& is_array( $result['metaBefore'] ?? null )
			&& is_array( $result['metaAfter'] ?? null )
			&& is_array( $result['saveEvents'] ?? null )
			&& is_array( $result['sendEvents'] ?? null )
			&& is_array( $result['dieCalls'] ?? null );
	}

	private static function collect_media_upload_send_failures( array &$failures, array $case, array $result ): void {
		$token        = self::media_upload_dispatch_token( (string) ( $case['token'] ?? '' ) );
		$allowed_id   = (int) ( $result['allowedId'] ?? 0 );
		$denied_id    = (int) ( $result['deniedId'] ?? 0 );
		$new_parent   = (int) ( $result['newParentId'] ?? 0 );
		$output       = (string) ( $result['output'] ?? '' );
		$before_posts = $result['postsBefore'];
		$after_posts  = $result['postsAfter'];
		$after_meta   = $result['metaAfter'];

		self::collect_failure(
			$failures,
			str_contains( $output, 'win.send_to_editor(' )
				&& str_contains( $output, 'data-cfz-upload' )
				&& str_contains( $output, $token )
				&& ! str_contains( $output, 'Denied title ' . $token )
				&& 1 === count( $result['sendEvents'] )
				&& $allowed_id === (int) ( $result['sendEvents'][0]['id'] ?? 0 )
				&& true === ( $result['sendEvents'][0]['hasRel'] ?? null ),
			'media_upload_form_handler() send branch exits through media_send_to_editor() with filtered allowed attachment HTML only',
			array(
				'output'     => self::describe_string( $output ),
				'sendEvents' => $result['sendEvents'],
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $result['saveEvents'] )
				&& $allowed_id === (int) ( $result['saveEvents'][0]['id'] ?? 0 )
				&& self::media_upload_dispatch_post_matches(
					$after_posts,
					$allowed_id,
					array(
						'post_title'   => 'Dispatch title ' . $token,
						'post_content' => 'Dispatch content ' . $token . ' filtered-' . $token,
						'post_excerpt' => 'Dispatch excerpt ' . $token,
						'menu_order'   => 7,
						'post_parent'  => $new_parent,
					)
				)
				&& ( $after_meta[ (string) $allowed_id ] ?? null ) === 'Alt ' . $token,
			'allowed send attachment fields, parent, menu order, filtered content, and stripped alt meta are persisted',
			array(
				'saveEvents' => $result['saveEvents'],
				'afterPost'  => $after_posts[ (string) $allowed_id ] ?? null,
				'afterMeta'  => $after_meta[ (string) $allowed_id ] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			self::media_upload_dispatch_post_unchanged( $before_posts, $after_posts, array( $denied_id ) )
				&& ( $result['metaBefore'][ (string) $denied_id ] ?? null ) === ( $after_meta[ (string) $denied_id ] ?? null )
				&& array( $allowed_id, $denied_id ) === array_map(
					static function ( array $event ): int {
						return (int) ( $event['postId'] ?? 0 );
					},
					$result['capEvents']
				),
			'denied attachment is capability-checked but not saved, sent, or meta-mutated',
			array(
				'capEvents'  => $result['capEvents'],
				'beforePost' => $before_posts[ (string) $denied_id ] ?? null,
				'afterPost'  => $after_posts[ (string) $denied_id ] ?? null,
			)
		);
	}

	private static function collect_media_upload_gallery_exit_failures( array &$failures, array $case, array $result ): void {
		unset( $case );
		$output = (string) ( $result['output'] ?? '' );

		self::collect_failure(
			$failures,
			str_contains( $output, 'win.tb_remove();' )
				&& array() === ( $result['saveEvents'] ?? array() )
				&& array() === ( $result['sendEvents'] ?? array() )
				&& self::media_upload_dispatch_post_unchanged( $result['postsBefore'], $result['postsAfter'], array_map( 'intval', $result['allTrackedIds'] ?? array() ) ),
			'insert-gallery branch exits after closing Thickbox without saving or sending attachments',
			array(
				'output'     => self::describe_string( $output ),
				'saveEvents' => $result['saveEvents'] ?? array(),
				'sendEvents' => $result['sendEvents'] ?? array(),
			)
		);
	}

	private static function collect_media_upload_type_error_failures( array &$failures, array $case, array $result ): void {
		unset( $case );
		$output        = (string) ( $result['output'] ?? '' );
		$error_message = (string) ( $result['errorMessage'] ?? '' );

		self::collect_failure(
			$failures,
			str_contains( $output, 'id="media-upload-error"' )
				&& str_contains( $output, 'Upload failed' )
				&& str_contains( $output, '&lt;script&gt;alert(1)&lt;/script&gt;' )
				&& ! str_contains( $output, $error_message )
				&& array() === ( $result['saveEvents'] ?? array() )
				&& array() === ( $result['sendEvents'] ?? array() ),
			'media_upload_type_form() renders escaped WP_Error upload failure and exits before media items',
			array(
				'output'       => self::describe_string( $output ),
				'errorMessage' => $error_message,
			)
		);
	}

	private static function media_upload_dispatch_post_matches( array $posts, int $id, array $expected ): bool {
		$key = (string) $id;
		foreach ( $expected as $field => $value ) {
			if ( (string) $value !== (string) ( $posts[ $key ][ $field ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function media_upload_dispatch_post_unchanged( array $before, array $after, array $ids ): bool {
		foreach ( $ids as $id ) {
			$key = (string) (int) $id;
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function media_upload_dispatch_post_state( array $ids ): array {
		$out = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
			$post = \get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				$out[ (string) $id ] = null;
				continue;
			}

			$out[ (string) $id ] = array(
				'post_title'   => (string) $post->post_title,
				'post_content' => (string) $post->post_content,
				'post_excerpt' => (string) $post->post_excerpt,
				'menu_order'   => (int) $post->menu_order,
				'post_parent'  => (int) $post->post_parent,
				'post_type'    => (string) $post->post_type,
			);
		}

		return $out;
	}

	private static function media_upload_dispatch_alt_state( array $ids ): array {
		$out = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
			$out[ (string) $id ] = \get_post_meta( $id, '_wp_attachment_image_alt', true );
		}

		return $out;
	}

	private static function check_media_url_insert_dispatch_exits( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-url-insert-dispatch-exits',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_url_insert_cases( $ctx ) as $case ) {
			$run    = self::run_media_url_insert_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'   => $result['returned'] ?? null,
					'output'     => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'eventCount' => is_array( $result['events'] ?? null ) ? count( $result['events'] ) : null,
					'before'     => $result['contentBefore'] ?? array(),
					'after'      => $result['contentAfter'] ?? array(),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_url_insert_child_result_has_expected_shape( $result ),
				"{$case['label']} child exits cleanly and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_url_insert_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				false === (bool) ( $result['returned'] ?? true )
					&& null === ( $result['throwable'] ?? null ),
				"{$case['label']} reaches media_send_to_editor() exit without unexpected exceptions",
				array(
					'returned'  => $result['returned'] ?? null,
					'throwable' => $result['throwable'] ?? null,
				)
			);

			self::collect_media_url_insert_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-url-insert-dispatch-exits',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_url_insert_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = self::media_upload_dispatch_token( 'url_' . $ctx->identifier( 4, 9 ) );

		return array(
			array(
				'label'        => 'image-relative-src',
				'seed'         => $ctx->fork( 'image' )->seed(),
				'iteration'    => $ctx->iteration(),
				'token'        => $token . '_image',
				'mediaType'    => 'image',
				'src'          => 'media.example.test/uploads/image-' . $token . '.jpg?caption=<script>alert(1)</script>',
				'alt'          => 'Alt <script>alert(1)</script> "' . $token,
				'align'        => 'left" data-cfz="' . $token,
				'title'        => '',
				'expectedHook' => 'image_send_to_editor_url',
				'expectedType' => 'image',
				'expectedTag'  => 'img',
				'expectsHttp'  => true,
			),
			array(
				'label'         => 'image-default-media-type',
				'seed'          => $ctx->fork( 'image-default' )->seed(),
				'iteration'     => $ctx->iteration(),
				'token'         => $token . '_image_default',
				'mediaType'     => 'image',
				'omitMediaType' => true,
				'src'           => 'https://images.example.test/default-' . $token . '.png',
				'alt'           => 'Default image alt <script>alert(1)</script> ' . $token,
				'align'         => 'center',
				'title'         => '',
				'expectedHook'  => 'image_send_to_editor_url',
				'expectedType'  => 'image',
				'expectedTag'   => 'img',
				'expectsHttp'   => false,
			),
			array(
				'label'        => 'file-explicit-title',
				'seed'         => $ctx->fork( 'file' )->seed(),
				'iteration'    => $ctx->iteration(),
				'token'        => $token . '_file',
				'mediaType'    => 'file',
				'src'          => 'https://files.example.test/report-' . $token . '.pdf?<script>alert(1)</script>',
				'alt'          => '',
				'align'        => '',
				'title'        => 'Report <script>alert(1)</script> "' . $token,
				'expectedHook' => 'file_send_to_editor_url',
				'expectedType' => 'file',
				'expectedTag'  => 'a',
				'expectsHttp'  => false,
			),
			array(
				'label'        => 'audio-basename-title',
				'seed'         => $ctx->fork( 'audio' )->seed(),
				'iteration'    => $ctx->iteration(),
				'token'        => $token . '_audio',
				'mediaType'    => 'file',
				'src'          => 'audio.example.test/tracks/song-' . $token . '.MP3',
				'alt'          => '',
				'align'        => '',
				'title'        => '',
				'expectedHook' => 'audio_send_to_editor_url',
				'expectedType' => 'audio',
				'expectedTag'  => 'a',
				'expectsHttp'  => true,
			),
			array(
				'label'        => 'video-normalized-type',
				'seed'         => $ctx->fork( 'video' )->seed(),
				'iteration'    => $ctx->iteration(),
				'token'        => $token . '_video',
				'mediaType'    => 'not-image',
				'src'          => 'https://video.example.test/clips/movie-' . $token . '.mp4',
				'alt'          => '',
				'align'        => '',
				'title'        => 'Movie ' . $token,
				'expectedHook' => 'video_send_to_editor_url',
				'expectedType' => 'video',
				'expectedTag'  => 'a',
				'expectsHttp'  => false,
			),
			array(
				'label'        => 'misleading-video-pdf-stays-file',
				'seed'         => $ctx->fork( 'misleading-video' )->seed(),
				'iteration'    => $ctx->iteration(),
				'token'        => $token . '_video_pdf',
				'mediaType'    => 'video',
				'src'          => 'https://files.example.test/not-video-' . $token . '.pdf',
				'alt'          => '',
				'align'        => '',
				'title'        => 'Not video ' . $token,
				'expectedHook' => 'file_send_to_editor_url',
				'expectedType' => 'file',
				'expectedTag'  => 'a',
				'expectsHttp'  => false,
			),
		);
	}

	private static function run_media_url_insert_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates wp_media_upload_handler() URL insert media_send_to_editor() exits in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_url_insert_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_url_insert_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_url_insert_child( $case );
PHP;
	}

	public static function run_media_url_insert_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$state = array(
			'ok'            => false,
			'label'         => (string) ( $case['label'] ?? 'url-insert' ),
			'token'         => self::media_upload_dispatch_token( (string) ( $case['token'] ?? 'url_token' ) ),
			'events'        => array(),
			'contentBefore' => self::media_url_insert_content_counts(),
			'contentAfter'  => array(),
			'returned'      => false,
			'throwable'     => null,
			'output'        => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$GLOBALS['pagenow']         = 'media-upload.php';
			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'POST';
			$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?type=' . rawurlencode( (string) ( $case['mediaType'] ?? 'image' ) ) . '&tab=type_url';
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=image&tab=type_url';
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-url-insert';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.45';
			$_SERVER['SERVER_PORT']     = '80';

			$_GET     = array(
				'type' => (string) ( $case['mediaType'] ?? 'image' ),
				'tab'  => 'type_url',
			);
			$_POST    = array(
				'insertonlybutton' => '1',
				'src'              => (string) ( $case['src'] ?? '' ),
				'title'            => (string) ( $case['title'] ?? '' ),
				'alt'              => (string) ( $case['alt'] ?? '' ),
				'align'            => (string) ( $case['align'] ?? '' ),
			);
			if ( empty( $case['omitMediaType'] ) ) {
				$_POST['media_type'] = (string) ( $case['mediaType'] ?? 'image' );
			}
			$_REQUEST = $_GET + $_POST;
			$_FILES   = array();
			$_COOKIE  = array();

			$image_filter = static function ( string $html, string $src, string $alt, string $align ) use ( &$state ): string {
				$state['events'][] = array(
					'hook'  => 'image_send_to_editor_url',
					'html'  => $html,
					'src'   => $src,
					'alt'   => $alt,
					'align' => $align,
				);
				return $html . '<span data-cfz-url="' . esc_attr( $state['token'] ) . '">image</span>';
			};
			$file_filter  = static function ( string $html, string $src, string $title ) use ( &$state ): string {
				$state['events'][] = array(
					'hook'  => current_filter(),
					'html'  => $html,
					'src'   => $src,
					'title' => $title,
				);
				return $html . '<span data-cfz-url="' . esc_attr( $state['token'] ) . '">file</span>';
			};

			\add_filter( 'image_send_to_editor_url', $image_filter, 10, 4 );
			\add_filter( 'file_send_to_editor_url', $file_filter, 10, 3 );
			\add_filter( 'audio_send_to_editor_url', $file_filter, 10, 3 );
			\add_filter( 'video_send_to_editor_url', $file_filter, 10, 3 );

			try {
				\wp_media_upload_handler();
				$state['returned'] = true;
			} finally {
				\remove_filter( 'video_send_to_editor_url', $file_filter, 10 );
				\remove_filter( 'audio_send_to_editor_url', $file_filter, 10 );
				\remove_filter( 'file_send_to_editor_url', $file_filter, 10 );
				\remove_filter( 'image_send_to_editor_url', $image_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_url_insert_content_counts(): array {
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \Component_Fuzz_WPDB_Stub ) {
			return $GLOBALS['wpdb']->component_fuzz_content_counts();
		}

		return array();
	}

	private static function media_url_insert_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['events'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null );
	}

	private static function collect_media_url_insert_failures( array &$failures, array $case, array $result ): void {
		$output = (string) ( $result['output'] ?? '' );
		$events = is_array( $result['events'] ?? null ) ? $result['events'] : array();
		$event  = $events[0] ?? array();
		$html   = (string) ( $event['html'] ?? '' );
		$src    = (string) ( $event['src'] ?? '' );
		$hook   = (string) ( $event['hook'] ?? '' );

		self::collect_failure(
			$failures,
			1 === count( $events )
				&& (string) ( $case['expectedHook'] ?? '' ) === $hook
				&& str_contains( $output, 'win.send_to_editor(' )
				&& str_contains( $output, 'data-cfz-url' )
				&& str_contains( $output, (string) ( $result['token'] ?? '' ) )
				&& ! str_contains( $output, '<script>alert(1)</script>' )
				&& ! str_contains( $output, '</script><script>' ),
			'wp_media_upload_handler() URL insert branch exits through media_send_to_editor with the expected filtered hook output',
			array(
				'case'   => $case,
				'events' => $events,
				'output' => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() ),
			'URL insert dispatch does not create attachment rows or metadata',
			array(
				'before' => $result['contentBefore'] ?? array(),
				'after'  => $result['contentAfter'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			'' !== $src
				&& ! str_contains( $src, '<' )
				&& ! str_contains( $src, '>' )
				&& ( empty( $case['expectsHttp'] ) || str_starts_with( $src, 'http://' ) ),
			'URL insert filters receive a sanitized URL with scheme-normalized relative sources',
			array(
				'case' => $case,
				'src'  => self::describe_string( $src ),
			)
		);

		if ( 'img' === ( $case['expectedTag'] ?? null ) ) {
			self::collect_failure(
				$failures,
				str_contains( $html, '<img ' )
					&& str_contains( $html, 'src=' )
					&& str_contains( $html, 'alt=' )
					&& str_contains( $html, "class='align" )
					&& ! str_contains( $html, '<script>' )
					&& str_contains( (string) ( $event['alt'] ?? '' ), '&lt;script&gt;' )
					&& (
						str_contains( (string) ( $case['align'] ?? '' ), '"' )
							? str_contains( (string) ( $event['align'] ?? '' ), '&quot;' )
							: (string) ( $case['align'] ?? '' ) === (string) ( $event['align'] ?? '' )
					),
				'image URL insert escapes alt and align attributes before filtering',
				array(
					'event' => $event,
					'html'  => self::describe_string( $html ),
				)
			);
		} else {
			self::collect_failure(
				$failures,
				str_contains( $html, '<a ' )
					&& str_contains( $html, "href='" )
					&& ! str_contains( $html, '<script>' )
					&& ! str_contains( $html, '</script>' )
					&& '' !== (string) ( $event['title'] ?? '' ),
				'non-image URL insert renders escaped link HTML with explicit or basename-derived title text',
				array(
					'event' => $event,
					'html'  => self::describe_string( $html ),
				)
			);
		}
	}

	private static function check_media_gallery_save_iframe_dispatch( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-gallery-save-iframe-dispatch',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_gallery_save_cases( $ctx ) as $case ) {
			$run    = self::run_media_gallery_save_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'       => $result['returned'] ?? null,
					'output'         => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'expectedIds'    => $result['expectedIds'] ?? array(),
					'fieldEventIds'  => array_map(
						static function ( array $event ): int {
							return (int) ( $event['id'] ?? 0 );
						},
						is_array( $result['fieldEvents'] ?? null ) ? $result['fieldEvents'] : array()
					),
					'formUrlEvents'  => $result['formUrlEvents'] ?? array(),
					'scriptStatus'   => $result['scriptStatus'] ?? array(),
					'saveEventCount' => is_array( $result['saveEvents'] ?? null ) ? count( $result['saveEvents'] ) : null,
					'sendEventCount' => is_array( $result['sendEvents'] ?? null ) ? count( $result['sendEvents'] ) : null,
					'dieCalls'       => $result['dieCalls'] ?? array(),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_gallery_save_child_result_has_expected_shape( $result ),
				"{$case['label']} child renders gallery save iframe and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_gallery_save_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& null === ( $result['throwable'] ?? null )
					&& array() === ( $result['dieCalls'] ?? array() ),
				"{$case['label']} returns normally after wp_iframe() without wp_die or unexpected exceptions",
				array(
					'returned'  => $result['returned'] ?? null,
					'throwable' => $result['throwable'] ?? null,
					'dieCalls'  => $result['dieCalls'] ?? array(),
				)
			);

			self::collect_media_gallery_save_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-gallery-save-iframe-dispatch',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_gallery_save_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, string $scenario, bool $chromeless, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$token = self::media_upload_dispatch_token( 'gallery_' . $case_ctx->identifier( 4, 9 ) );

			return array(
				'label'      => $label,
				'scenario'   => $scenario,
				'chromeless' => $chromeless,
				'seed'       => $case_ctx->seed(),
				'iteration'  => $case_ctx->iteration(),
				'token'      => $token,
			);
		};

		return array(
			$build( 'parent-gallery-save', 'parent', false, $ctx->fork( 'parent-gallery' ) ),
			$build( 'attachment-gallery-save-chromeless', 'attachment', true, $ctx->fork( 'attachment-gallery' ) ),
		);
	}

	private static function run_media_gallery_save_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates wp_media_upload_handler() gallery save iframe output in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_gallery_save_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_gallery_save_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_gallery_save_child( $case );
PHP;
	}

	public static function run_media_gallery_save_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx       = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$scenario  = (string) ( $case['scenario'] ?? 'parent' );
		$token     = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state     = array(
			'ok'            => false,
			'label'         => (string) ( $case['label'] ?? 'gallery-save' ),
			'scenario'      => $scenario,
			'token'         => $token,
			'postId'        => 0,
			'parentId'      => 0,
			'expectedIds'   => array(),
			'absentIds'     => array(),
			'allTrackedIds' => array(),
			'postsBefore'   => array(),
			'postsAfter'    => array(),
			'metaBefore'    => array(),
			'metaAfter'     => array(),
			'fieldEvents'   => array(),
			'formUrlEvents' => array(),
			'saveEvents'    => array(),
			'sendEvents'    => array(),
			'dieCalls'      => array(),
			'scriptStatus'  => array(),
			'returned'      => false,
			'returnType'    => null,
			'throwable'     => null,
			'output'        => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$tracked_ids             = array_map( 'intval', $state['allTrackedIds'] );
				$state['output']         = $output;
				$state['postsAfter']     = self::media_upload_dispatch_post_state( $tracked_ids );
				$state['metaAfter']      = self::media_upload_dispatch_alt_state( $tracked_ids );
				$state['scriptStatus']   = self::media_gallery_save_script_status( 'admin-gallery' );
				$state['ok']             = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$parent_id = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$image_one = self::seed_attachment(
				$ctx->fork( 'image-one' ),
				'image/jpeg',
				array(
					'parent_id'  => $parent_id,
					'post_title' => 'Gallery one <script>alert(1)</script> ' . $token,
					'alt'        => 'Gallery alt one <script>alert(1)</script> ' . $token,
				)
			);
			$image_two = self::seed_attachment(
				$ctx->fork( 'image-two' ),
				'image/png',
				array(
					'parent_id'  => $parent_id,
					'post_title' => 'Gallery two <script>alert(1)</script> ' . $token,
					'alt'        => 'Gallery alt two <script>alert(1)</script> ' . $token,
				)
			);
			$outside = self::seed_attachment(
				$ctx->fork( 'outside' ),
				'application/pdf',
				array(
					'post_title' => 'Outside gallery <script>alert(1)</script> ' . $token,
				)
			);

			$image_one_id = (int) $image_one->ID;
			$image_two_id = (int) $image_two->ID;
			$outside_id   = (int) $outside->ID;
			$post_id      = 'attachment' === $scenario ? $image_one_id : $parent_id;
			$expected_ids = 'attachment' === $scenario ? array( $image_one_id ) : array( $image_one_id, $image_two_id );
			$absent_ids   = 'attachment' === $scenario ? array( $image_two_id, $outside_id ) : array( $outside_id );
			$tracked_ids  = array( $parent_id, $image_one_id, $image_two_id, $outside_id );

			$state['postId']        = $post_id;
			$state['parentId']      = $parent_id;
			$state['expectedIds']   = $expected_ids;
			$state['absentIds']     = $absent_ids;
			$state['allTrackedIds'] = $tracked_ids;
			$state['postsBefore']   = self::media_upload_dispatch_post_state( $tracked_ids );
			$state['metaBefore']    = self::media_upload_dispatch_alt_state( $tracked_ids );

			$GLOBALS['pagenow'] = 'media-upload.php';
			$GLOBALS['type']    = 'image';
			$GLOBALS['tab']     = 'gallery';
			$GLOBALS['body_id'] = 'component-fuzz-gallery-save';

			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'POST';
			$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?type=image&tab=gallery&post_id=' . rawurlencode( (string) $post_id );
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=image&tab=gallery&post_id=' . rawurlencode( (string) $post_id );
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-gallery-save';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.46';
			$_SERVER['SERVER_PORT']     = '80';

			$_GET = array(
				'type'    => 'image',
				'tab'     => 'gallery',
				'post_id' => (string) $post_id,
			);
			if ( ! empty( $case['chromeless'] ) ) {
				$_GET['chromeless'] = '1';
			}
			$_POST = array(
				'_wpnonce'    => \wp_create_nonce( 'media-form' ),
				'save'        => 'Save all changes',
				'post_id'     => (string) $post_id,
				'type'        => 'image',
				'tab'         => 'gallery',
				'attachments' => array(
					$image_one_id => array(
						'post_title'   => 'Submitted title <script>alert(1)</script> ' . $token,
						'post_content' => 'Submitted content ' . $token,
						'post_excerpt' => 'Submitted excerpt ' . $token,
						'menu_order'   => '99',
						'image_alt'    => 'Submitted alt <script>alert(1)</script> ' . $token,
					),
					$image_two_id => array(
						'post_title'   => 'Submitted sibling title ' . $token,
						'post_content' => 'Submitted sibling content ' . $token,
						'post_excerpt' => 'Submitted sibling excerpt ' . $token,
						'menu_order'   => '88',
						'image_alt'    => 'Submitted sibling alt ' . $token,
					),
				),
			);
			$_REQUEST = $_GET + $_POST;
			$_FILES   = array();
			$_COOKIE  = array();

			\wp_register_script( 'admin-gallery', '/wp-admin/js/gallery.js', array(), false );

			$form_url_filter = static function ( string $url, string $type ) use ( &$state, $token ): string {
				$state['formUrlEvents'][] = array(
					'url'  => $url,
					'type' => $type,
				);
				return \add_query_arg( 'cfz_gallery', $token, $url );
			};
			$fields_filter   = static function ( array $fields, \WP_Post $post ) use ( &$state, $token ): array {
				$state['fieldEvents'][] = array(
					'id'    => (int) $post->ID,
					'title' => (string) $post->post_title,
				);
				$fields['component_fuzz_gallery'] = array(
					'label' => 'Component Fuzz Gallery',
					'value' => 'Gallery custom field <script>alert(1)</script> ' . $token,
				);
				return $fields;
			};
			$save_filter     = static function ( array $post, array $attachment ) use ( &$state ): array {
				$state['saveEvents'][] = array(
					'id'    => (int) ( $post['ID'] ?? 0 ),
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $post;
			};
			$send_filter     = static function ( string $html, int $send_id, array $attachment ) use ( &$state ): string {
				$state['sendEvents'][] = array(
					'id'    => $send_id,
					'html'  => $html,
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $html;
			};
			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$die_handler_filter = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};

			\add_filter( 'media_upload_form_url', $form_url_filter, 10, 2 );
			\add_filter( 'attachment_fields_to_edit', $fields_filter, 11, 2 );
			\add_filter( 'attachment_fields_to_save', $save_filter, 10, 2 );
			\add_filter( 'media_send_to_editor', $send_filter, 10, 3 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			try {
				$return_value        = \wp_media_upload_handler();
				$state['returnType'] = gettype( $return_value );
				$state['returned']   = true;
			} finally {
				\remove_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
				\remove_filter( 'media_send_to_editor', $send_filter, 10 );
				\remove_filter( 'attachment_fields_to_save', $save_filter, 10 );
				\remove_filter( 'attachment_fields_to_edit', $fields_filter, 11 );
				\remove_filter( 'media_upload_form_url', $form_url_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_gallery_save_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['expectedIds'] ?? null )
			&& is_array( $result['absentIds'] ?? null )
			&& is_array( $result['postsBefore'] ?? null )
			&& is_array( $result['postsAfter'] ?? null )
			&& is_array( $result['metaBefore'] ?? null )
			&& is_array( $result['metaAfter'] ?? null )
			&& is_array( $result['fieldEvents'] ?? null )
			&& is_array( $result['formUrlEvents'] ?? null )
			&& is_array( $result['saveEvents'] ?? null )
			&& is_array( $result['sendEvents'] ?? null )
			&& is_array( $result['dieCalls'] ?? null )
			&& is_array( $result['scriptStatus'] ?? null );
	}

	private static function media_gallery_save_script_status( string $handle ): array {
		$status = array();
		foreach ( array( 'registered', 'enqueued', 'queue', 'to_do', 'done' ) as $state ) {
			$status[ $state ] = \wp_script_is( $handle, $state );
		}
		return $status;
	}

	private static function media_type_iframe_style_status(): array {
		$status = array();
		foreach ( array( 'colors', 'deprecated-media' ) as $handle ) {
			$status[ $handle ] = array();
			foreach ( array( 'registered', 'enqueued', 'queue', 'to_do', 'done' ) as $state ) {
				$status[ $handle ][ $state ] = \wp_style_is( $handle, $state );
			}
		}
		return $status;
	}

	private static function collect_media_gallery_save_failures( array &$failures, array $case, array $result ): void {
		$output       = (string) ( $result['output'] ?? '' );
		$token        = self::media_upload_dispatch_token( (string) ( $result['token'] ?? $case['token'] ?? '' ) );
		$expected_ids = array_values( array_map( 'intval', $result['expectedIds'] ?? array() ) );
		$absent_ids   = array_values( array_map( 'intval', $result['absentIds'] ?? array() ) );
		$field_ids    = array_map(
			static function ( array $event ): int {
				return (int) ( $event['id'] ?? 0 );
			},
			$result['fieldEvents'] ?? array()
		);
		sort( $expected_ids );
		sort( $field_ids );

		$expected_media_markup = true;
		foreach ( $expected_ids as $id ) {
			$expected_media_markup = $expected_media_markup
				&& str_contains( $output, "id='media-item-$id'" )
				&& str_contains( $output, "attachments[$id][menu_order]" )
				&& str_contains( $output, "attachments[$id][component_fuzz_gallery]" );
		}

		$absent_media_markup = true;
		foreach ( $absent_ids as $id ) {
			$absent_media_markup = $absent_media_markup && ! str_contains( $output, "id='media-item-$id'" );
		}

		self::collect_failure(
			$failures,
			str_contains( $output, 'pagenow = \'media-upload-popup\'' )
				&& str_contains( $output, '<body id="component-fuzz-gallery-save" class="wp-core-ui no-js ' )
				&& ! str_contains( $output, 'id="media-upload-notice"' )
				&& ! str_contains( $output, 'Saved.' )
				&& str_contains( $output, 'id="gallery-form"' )
				&& str_contains( $output, 'id="save-all"' )
				&& str_contains( $output, 'id="insert-gallery"' )
				&& str_contains( $output, 'id="gallery-settings"' )
				&& str_contains( $output, 'cfz_gallery=' . rawurlencode( $token ) )
				&& $expected_media_markup
				&& $absent_media_markup
				&& ! str_contains( $output, 'win.tb_remove();' )
				&& ! str_contains( $output, 'win.send_to_editor(' ),
			'wp_media_upload_handler() save branch renders the gallery iframe form without leaking generic upload notices or triggering close/send exits',
			array(
				'output'      => self::describe_string( $output ),
				'expectedIds' => $expected_ids,
				'absentIds'   => $absent_ids,
			)
		);

		self::collect_failure(
			$failures,
			! str_contains( $output, 'Submitted title <script>alert(1)</script> ' . $token )
				&& ! str_contains( $output, 'Gallery one <script>alert(1)</script> ' . $token )
				&& ! str_contains( $output, 'Gallery custom field <script>alert(1)</script> ' . $token )
				&& str_contains( $output, 'Gallery custom field &lt;script&gt;alert(1)&lt;/script&gt; ' . $token ),
			'gallery save iframe escapes generated attachment titles and custom field values',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			$expected_ids === $field_ids
				&& 1 === count( $result['formUrlEvents'] ?? array() )
				&& 'image' === (string) ( $result['formUrlEvents'][0]['type'] ?? '' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'tab=gallery' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'post_id=' . (string) ( $result['postId'] ?? 0 ) ),
			'gallery save iframe routes the gallery form URL and edit fields through the expected filters for rendered attachments only',
			array(
				'fieldEvents'   => $result['fieldEvents'] ?? array(),
				'formUrlEvents' => $result['formUrlEvents'] ?? array(),
				'expectedIds'   => $expected_ids,
			)
		);

		$script_status = $result['scriptStatus'] ?? array();
		self::collect_failure(
			$failures,
			true === ( $script_status['enqueued'] ?? false )
				|| true === ( $script_status['to_do'] ?? false )
				|| true === ( $script_status['done'] ?? false ),
			'save branch enqueues the legacy admin-gallery script handle before rendering the iframe',
			array( 'scriptStatus' => $script_status )
		);

		self::collect_failure(
			$failures,
			empty( $case['chromeless'] )
				? str_contains( $output, 'id="media-upload-header"' )
				: ! str_contains( $output, 'id="media-upload-header"' ),
			'media_upload_header() honors the chromeless request flag inside the gallery iframe',
			array(
				'chromeless' => $case['chromeless'] ?? false,
				'output'    => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			( $result['postsBefore'] ?? array() ) === ( $result['postsAfter'] ?? array() )
				&& ( $result['metaBefore'] ?? array() ) === ( $result['metaAfter'] ?? array() )
				&& array() === ( $result['saveEvents'] ?? array() )
				&& array() === ( $result['sendEvents'] ?? array() ),
			'save branch does not fall through to attachment field persistence or send-to-editor handling',
			array(
				'postsBefore' => $result['postsBefore'] ?? array(),
				'postsAfter'  => $result['postsAfter'] ?? array(),
				'metaBefore'  => $result['metaBefore'] ?? array(),
				'metaAfter'   => $result['metaAfter'] ?? array(),
				'saveEvents'  => $result['saveEvents'] ?? array(),
				'sendEvents'  => $result['sendEvents'] ?? array(),
			)
		);
	}

	private static function check_media_type_iframe_dispatch( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-type-iframe-dispatch',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_type_iframe_cases( $ctx ) as $case ) {
			$run    = self::run_media_type_iframe_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'          => $result['returned'] ?? null,
					'returnType'        => $result['returnType'] ?? null,
					'output'            => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'contentBefore'     => $result['contentBefore'] ?? array(),
					'contentAfter'      => $result['contentAfter'] ?? array(),
					'formUrlEvents'     => $result['formUrlEvents'] ?? array(),
					'typeUrlEventCount' => is_array( $result['typeUrlEvents'] ?? null ) ? count( $result['typeUrlEvents'] ) : null,
					'uploadParamCount'  => is_array( $result['uploadPostParamEvents'] ?? null ) ? count( $result['uploadPostParamEvents'] ) : null,
					'pluploadCount'     => is_array( $result['pluploadEvents'] ?? null ) ? count( $result['pluploadEvents'] ) : null,
					'iframeActions'     => $result['iframeActionCounts'] ?? array(),
					'styleStatus'       => $result['styleStatus'] ?? array(),
					'saveEventCount'    => is_array( $result['saveEvents'] ?? null ) ? count( $result['saveEvents'] ) : null,
					'sendEventCount'    => is_array( $result['sendEvents'] ?? null ) ? count( $result['sendEvents'] ) : null,
					'dieCalls'          => $result['dieCalls'] ?? array(),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_type_iframe_child_result_has_expected_shape( $result ),
				"{$case['label']} child renders type iframe and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_type_iframe_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& 'NULL' === (string) ( $result['returnType'] ?? '' )
					&& null === ( $result['throwable'] ?? null )
					&& array() === ( $result['dieCalls'] ?? array() ),
				"{$case['label']} returns normally after wp_iframe() without wp_die or unexpected exceptions",
				array(
					'returned'  => $result['returned'] ?? null,
					'returnType' => $result['returnType'] ?? null,
					'throwable' => $result['throwable'] ?? null,
					'dieCalls'  => $result['dieCalls'] ?? array(),
				)
			);

			self::collect_media_type_iframe_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-type-iframe-dispatch',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_type_iframe_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, string $scenario, array $args, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$token = self::media_upload_dispatch_token( 'type_' . $case_ctx->identifier( 4, 9 ) );

			return array_merge(
				array(
					'label'     => $label,
					'scenario'  => $scenario,
					'seed'      => $case_ctx->seed(),
					'iteration' => $case_ctx->iteration(),
					'token'     => $token,
				),
				$args
			);
		};

		return array(
			$build(
				'type-url-default-image',
				'type-url',
				array(
					'requestType'     => null,
					'expectedType'    => 'image',
					'disableCaptions' => false,
					'chromeless'      => false,
				),
				$ctx->fork( 'type-url-default-image' )
			),
			$build(
				'type-url-video',
				'type-url',
				array(
					'requestType'     => 'video',
					'expectedType'    => 'video',
					'disableCaptions' => true,
					'chromeless'      => false,
				),
				$ctx->fork( 'type-url-video' )
			),
			$build(
				'type-url-invalid-coerces-image',
				'type-url',
				array(
					'requestType'     => 'svg</script><script>alert(1)</script>',
					'expectedType'    => 'image',
					'disableCaptions' => true,
					'chromeless'      => true,
				),
				$ctx->fork( 'type-url-invalid' )
			),
			$build(
				'default-ignores-request-type',
				'default',
				array(
					'requestType'     => 'audio</script><script>alert(2)</script>',
					'requestTab'      => 'library',
					'expectedType'    => 'image',
					'disableCaptions' => false,
					'chromeless'      => false,
				),
				$ctx->fork( 'default-type-form' )
			),
		);
	}

	private static function run_media_type_iframe_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates wp_media_upload_handler() no-POST iframe branches in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_type_iframe_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_type_iframe_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_type_iframe_child( $case );
PHP;
	}

	public static function run_media_type_iframe_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx      = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$scenario = (string) ( $case['scenario'] ?? 'type-url' );
		$token    = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state    = array(
			'ok'                    => false,
			'label'                 => (string) ( $case['label'] ?? 'type-iframe' ),
			'scenario'              => $scenario,
			'token'                 => $token,
			'postId'                => 0,
			'expectedType'          => (string) ( $case['expectedType'] ?? 'image' ),
			'requestType'           => $case['requestType'] ?? null,
			'requestTab'            => $case['requestTab'] ?? null,
			'contentBefore'         => array(),
			'contentAfter'          => array(),
			'formUrlEvents'         => array(),
			'typeUrlEvents'         => array(),
			'disableCaptionEvents'  => array(),
			'uploadPostParamEvents' => array(),
			'pluploadEvents'        => array(),
			'uploadActionCounts'    => array(),
			'iframeActionCounts'    => array(),
			'adminEnqueueArgs'      => array(),
			'styleStatus'           => array(),
			'saveEvents'            => array(),
			'sendEvents'            => array(),
			'dieCalls'              => array(),
			'returned'              => false,
			'returnType'            => null,
			'throwable'             => null,
			'output'                => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['styleStatus']  = self::media_type_iframe_style_status();
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$post_id                  = self::seed_parent_post( $ctx->fork( 'post' ) );
			$state['postId']          = $post_id;
			$state['contentBefore']   = self::media_url_insert_content_counts();
			$request_type             = $case['requestType'] ?? null;
			$request_tab              = (string) ( $case['requestTab'] ?? ( 'type-url' === $scenario ? 'type_url' : 'type' ) );
			$unsafe_post_id           = (string) $post_id . '</script><script>alert(9)</script>';
			$GLOBALS['pagenow']       = 'media-upload.php';
			$GLOBALS['type']          = is_string( $request_type ) ? $request_type : 'image';
			$GLOBALS['tab']           = $request_tab;
			$GLOBALS['body_id']       = 'component-fuzz-type-iframe';

			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?tab=' . rawurlencode( $request_tab ) . '&post_id=' . rawurlencode( $unsafe_post_id );
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?tab=' . rawurlencode( $request_tab );
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-type-iframe';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.47';
			$_SERVER['SERVER_PORT']     = '80';

			$_GET = array(
				'tab'     => $request_tab,
				'post_id' => $unsafe_post_id,
			);
			if ( null !== $request_type ) {
				$_GET['type'] = (string) $request_type;
			}
			if ( ! empty( $case['chromeless'] ) ) {
				$_GET['chromeless'] = '1';
			}
			$_POST    = array();
			$_REQUEST = $_GET;
			$_FILES   = array();
			$_COOKIE  = array();

			$form_url_filter = static function ( string $url, string $type ) use ( &$state, $token ): string {
				$state['formUrlEvents'][] = array(
					'url'  => $url,
					'type' => $type,
				);
				return \add_query_arg( 'cfz_type_iframe', $token, $url );
			};
			$type_url_filter = static function ( string $form_html ) use ( &$state, $token ): string {
				$state['typeUrlEvents'][] = array(
					'hasImageOnly' => str_contains( $form_html, 'id="image-only"' ),
					'hasNotImage'  => str_contains( $form_html, 'id="not-image"' ),
					'hasCaption'   => str_contains( $form_html, 'id="caption"' ),
					'bytes'        => strlen( $form_html ),
				);
				return $form_html . '<input type="hidden" id="cfz-type-url-' . esc_attr( $token ) . '" value="' . esc_attr( $token ) . '" />';
			};
			$disable_captions_filter = static function ( $disabled ) use ( &$state, $case ): bool {
				$state['disableCaptionEvents'][] = $disabled;
				return ! empty( $case['disableCaptions'] );
			};
			$upload_post_params_filter = static function ( array $params ) use ( &$state, $token ): array {
				$state['uploadPostParamEvents'][] = $params;
				$params['component_fuzz_type_iframe'] = $token;
				return $params;
			};
			$plupload_filter = static function ( array $init ) use ( &$state, $token ): array {
				$state['pluploadEvents'][] = $init;
				$init['component_fuzz_type_iframe'] = $token;
				return $init;
			};
			$save_filter = static function ( array $post, array $attachment ) use ( &$state ): array {
				$state['saveEvents'][] = array(
					'id'    => (int) ( $post['ID'] ?? 0 ),
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $post;
			};
			$send_filter = static function ( string $html, int $send_id, array $attachment ) use ( &$state ): string {
				$state['sendEvents'][] = array(
					'id'    => $send_id,
					'html'  => $html,
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $html;
			};
			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$die_handler_filter = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};
			$tracked_actions = array(
				'pre-upload-ui',
				'pre-plupload-upload-ui',
				'post-plupload-upload-ui',
				'pre-html-upload-ui',
				'post-html-upload-ui',
				'post-upload-ui',
			);
			$action_callbacks = array();
			foreach ( $tracked_actions as $hook ) {
				$state['uploadActionCounts'][ $hook ] = 0;
				$action_callbacks[ $hook ] = static function () use ( &$state, $hook ): void {
					++$state['uploadActionCounts'][ $hook ];
				};
			}
			$iframe_hooks = array(
				'admin_enqueue_scripts',
				'admin_print_styles-media-upload-popup',
				'admin_print_styles',
				'admin_print_scripts-media-upload-popup',
				'admin_print_scripts',
				'admin_head-media-upload-popup',
				'admin_head',
				'admin_print_footer_scripts',
				'type-url' === $scenario ? 'admin_head_media_upload_type_url_form' : 'admin_head_media_upload_type_form',
			);
			$iframe_action_callbacks = array();
			foreach ( $iframe_hooks as $hook ) {
				$state['iframeActionCounts'][ $hook ] = 0;
				$iframe_action_callbacks[ $hook ] = static function ( $arg = null ) use ( &$state, $hook ): void {
					++$state['iframeActionCounts'][ $hook ];
					if ( 'admin_enqueue_scripts' === $hook ) {
						$state['adminEnqueueArgs'][] = $arg;
					}
				};
			}

			\add_filter( 'media_upload_form_url', $form_url_filter, 10, 2 );
			\add_filter( 'type_url_form_media', $type_url_filter, 10, 1 );
			\add_filter( 'disable_captions', $disable_captions_filter, 10, 1 );
			\add_filter( 'upload_post_params', $upload_post_params_filter, 10, 1 );
			\add_filter( 'plupload_init', $plupload_filter, 10, 1 );
			\add_filter( 'attachment_fields_to_save', $save_filter, 10, 2 );
			\add_filter( 'media_send_to_editor', $send_filter, 10, 3 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
			foreach ( $iframe_action_callbacks as $hook => $callback ) {
				\add_action( $hook, $callback, 10, 1 );
			}
			foreach ( $action_callbacks as $hook => $callback ) {
				\add_action( $hook, $callback );
			}

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			try {
				$return_value        = \wp_media_upload_handler();
				$state['returnType'] = gettype( $return_value );
				$state['returned']   = true;
			} finally {
				foreach ( $action_callbacks as $hook => $callback ) {
					\remove_action( $hook, $callback );
				}
				foreach ( $iframe_action_callbacks as $hook => $callback ) {
					\remove_action( $hook, $callback, 10 );
				}
				\remove_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
				\remove_filter( 'media_send_to_editor', $send_filter, 10 );
				\remove_filter( 'attachment_fields_to_save', $save_filter, 10 );
				\remove_filter( 'plupload_init', $plupload_filter, 10 );
				\remove_filter( 'upload_post_params', $upload_post_params_filter, 10 );
				\remove_filter( 'disable_captions', $disable_captions_filter, 10 );
				\remove_filter( 'type_url_form_media', $type_url_filter, 10 );
				\remove_filter( 'media_upload_form_url', $form_url_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_type_iframe_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null )
			&& is_array( $result['formUrlEvents'] ?? null )
			&& is_array( $result['typeUrlEvents'] ?? null )
			&& is_array( $result['disableCaptionEvents'] ?? null )
			&& is_array( $result['uploadPostParamEvents'] ?? null )
			&& is_array( $result['pluploadEvents'] ?? null )
			&& is_array( $result['uploadActionCounts'] ?? null )
			&& is_array( $result['iframeActionCounts'] ?? null )
			&& is_array( $result['adminEnqueueArgs'] ?? null )
			&& is_array( $result['styleStatus'] ?? null )
			&& is_array( $result['saveEvents'] ?? null )
			&& is_array( $result['sendEvents'] ?? null )
			&& is_array( $result['dieCalls'] ?? null );
	}

	private static function collect_media_type_iframe_failures( array &$failures, array $case, array $result ): void {
		$output        = (string) ( $result['output'] ?? '' );
		$token         = self::media_upload_dispatch_token( (string) ( $result['token'] ?? $case['token'] ?? '' ) );
		$expected_type = (string) ( $case['expectedType'] ?? 'image' );
		$is_type_url   = 'type-url' === (string) ( $case['scenario'] ?? '' );
		$post_id       = (int) ( $result['postId'] ?? 0 );
		$request_type  = is_string( $case['requestType'] ?? null ) ? (string) $case['requestType'] : '';
		$dynamic_hook  = $is_type_url ? 'admin_head_media_upload_type_url_form' : 'admin_head_media_upload_type_form';

		self::collect_failure(
			$failures,
			str_contains( $output, 'pagenow = \'media-upload-popup\'' )
				&& str_contains( $output, '<body id="component-fuzz-type-iframe" class="wp-core-ui no-js ' )
				&& str_contains( $output, '</html>' )
				&& str_contains( $output, '<script>post_id = ' . $post_id . ';</script>' )
				&& str_contains( $output, 'id="' . $expected_type . '-form"' )
				&& str_contains( $output, 'cfz_type_iframe=' . rawurlencode( $token ) )
				&& ! str_contains( $output, '</script><script>alert(' ),
			'no-POST media handler branch renders a complete iframe shell with cast post ID and filtered form action URL',
			array(
				'output'       => self::describe_string( $output ),
				'expectedType' => $expected_type,
				'postId'       => $post_id,
			)
		);

		$iframe_hooks = array(
			'admin_enqueue_scripts',
			'admin_print_styles-media-upload-popup',
			'admin_print_styles',
			'admin_print_scripts-media-upload-popup',
			'admin_print_scripts',
			'admin_head-media-upload-popup',
			'admin_head',
			'admin_print_footer_scripts',
			$dynamic_hook,
		);
		$iframe_counts = is_array( $result['iframeActionCounts'] ?? null ) ? $result['iframeActionCounts'] : array();
		$iframe_hooks_ok = true;
		foreach ( $iframe_hooks as $hook ) {
			$iframe_hooks_ok = $iframe_hooks_ok && 1 === (int) ( $iframe_counts[ $hook ] ?? 0 );
		}
		$style_status = is_array( $result['styleStatus'] ?? null ) ? $result['styleStatus'] : array();
		self::collect_failure(
			$failures,
			$iframe_hooks_ok
				&& array( 'media-upload-popup' ) === ( $result['adminEnqueueArgs'] ?? array() )
				&& isset( $style_status['colors'], $style_status['deprecated-media'] ),
			'wp_iframe() fires legacy popup hooks for the selected callback and records popup style status',
			array(
				'iframeActionCounts' => $iframe_counts,
				'adminEnqueueArgs'   => $result['adminEnqueueArgs'] ?? array(),
				'styleStatus'        => $style_status,
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $result['formUrlEvents'] ?? array() )
				&& $expected_type === (string) ( $result['formUrlEvents'][0]['type'] ?? '' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'type=' . $expected_type )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'tab=type' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'post_id=' . $post_id ),
			'media_upload_form_url receives the coerced callback media type and cast post ID',
			array( 'formUrlEvents' => $result['formUrlEvents'] ?? array() )
		);

		if ( $is_type_url ) {
			self::collect_media_type_url_iframe_failures( $failures, $case, $result, $output, $token, $expected_type );
		} else {
			self::collect_media_default_type_iframe_failures( $failures, $case, $result, $output, $token, $expected_type );
		}

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() )
				&& array() === ( $result['saveEvents'] ?? array() )
				&& array() === ( $result['sendEvents'] ?? array() ),
			'no-POST iframe dispatch does not mutate content or enter save/send-to-editor server paths',
			array(
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
				'saveEvents'    => $result['saveEvents'] ?? array(),
				'sendEvents'    => $result['sendEvents'] ?? array(),
			)
		);

		if ( '' !== $request_type && ! in_array( $request_type, array( 'audio', 'video', 'file' ), true ) ) {
			self::collect_failure(
				$failures,
				'image' === $expected_type
					&& ! str_contains( $output, 'id="' . $request_type . '-form"' )
					&& ! str_contains( $output, '</script><script>alert(' ),
				'invalid requested media types are coerced to the image callback without raw script leakage',
				array(
					'requestType'  => self::describe_string( $request_type ),
					'expectedType' => $expected_type,
					'output'       => self::describe_string( $output ),
				)
			);
		}
	}

	private static function collect_media_type_url_iframe_failures( array &$failures, array $case, array $result, string $output, string $token, string $expected_type ): void {
		self::collect_failure(
			$failures,
			str_contains( $output, 'Insert media from another website' )
				&& str_contains( $output, 'id="src"' )
				&& str_contains( $output, 'name="insertonlybutton"' )
				&& str_contains( $output, 'addExtImage' )
				&& str_contains( $output, 'id="cfz-type-url-' . $token . '"' )
				&& 1 === count( $result['typeUrlEvents'] ?? array() )
				&& array() === ( $result['uploadPostParamEvents'] ?? array() )
				&& array() === ( $result['pluploadEvents'] ?? array() ),
			'type_url branch selects media_upload_type_url_form(), applies type_url_form_media, and avoids uploader initialization',
			array(
				'typeUrlEvents'         => $result['typeUrlEvents'] ?? array(),
				'uploadPostParamEvents' => $result['uploadPostParamEvents'] ?? array(),
				'pluploadEvents'        => $result['pluploadEvents'] ?? array(),
				'output'                => self::describe_string( $output ),
			)
		);

		$is_image = 'image' === $expected_type;
		self::collect_failure(
			$failures,
			$is_image
				? (
					str_contains( $output, 'id="image-only" checked=' )
					&& ! str_contains( $output, '<table class="describe not-image">' )
				)
				: (
					str_contains( $output, 'id="not-image" checked=' )
					&& str_contains( $output, '<table class="describe not-image">' )
				),
			'type_url branch marks the expected image/generic URL form view after media type coercion',
			array(
				'expectedType' => $expected_type,
				'output'       => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			! empty( $case['disableCaptions'] )
				? ! str_contains( $output, 'id="caption"' )
				: str_contains( $output, 'id="caption"' ),
			'type_url branch threads disable_captions through the URL form caption controls',
			array(
				'disableCaptions'      => $case['disableCaptions'] ?? false,
				'disableCaptionEvents' => $result['disableCaptionEvents'] ?? array(),
				'output'               => self::describe_string( $output ),
			)
		);
	}

	private static function collect_media_default_type_iframe_failures( array &$failures, array $case, array $result, string $output, string $token, string $expected_type ): void {
		unset( $case );

		self::collect_failure(
			$failures,
			'image' === $expected_type
				&& str_contains( $output, 'Add media files from your computer' )
				&& str_contains( $output, 'id="media-upload-notice"' )
				&& str_contains( $output, 'id="media-upload-error"' )
				&& str_contains( $output, 'id="async-upload"' )
				&& str_contains( $output, 'name="html-upload"' )
				&& str_contains( $output, 'wpUploaderInit = ' )
				&& ! str_contains( $output, 'Insert media from another website' )
				&& array() === ( $result['typeUrlEvents'] ?? array() )
				&& 1 === count( $result['uploadPostParamEvents'] ?? array() )
				&& 1 === count( $result['pluploadEvents'] ?? array() )
				&& $token === ( $result['pluploadEvents'][0]['multipart_params']['component_fuzz_type_iframe'] ?? null ),
			'default no-POST branch selects media_upload_type_form() image upload UI and initializes upload filters only there',
			array(
				'uploadPostParamEvents' => $result['uploadPostParamEvents'] ?? array(),
				'pluploadEvents'        => $result['pluploadEvents'] ?? array(),
				'typeUrlEvents'         => $result['typeUrlEvents'] ?? array(),
				'output'                => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			array() === array_filter(
				$result['uploadActionCounts'] ?? array(),
				static function ( int $count ): bool {
					return 1 !== $count;
				}
			),
			'default no-POST branch fires upload UI hooks once while rendering the type form',
			array( 'uploadActionCounts' => $result['uploadActionCounts'] ?? array() )
		);
	}

	private static function check_media_upload_entry_dispatch( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-media-upload-entry-dispatch',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_upload_entry_cases( $ctx ) as $case ) {
			$run    = self::run_media_upload_entry_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'                => $result['returned'] ?? null,
					'throwable'               => $result['throwable'] ?? null,
					'dieCalls'                => $result['dieCalls'] ?? array(),
					'actionEvents'            => $result['actionEvents'] ?? array(),
					'defaultTypeEventCount'   => is_array( $result['defaultTypeEvents'] ?? null ) ? count( $result['defaultTypeEvents'] ) : null,
					'defaultTabEventCount'    => is_array( $result['defaultTabEvents'] ?? null ) ? count( $result['defaultTabEvents'] ) : null,
					'tabsEventCount'          => is_array( $result['tabsEvents'] ?? null ) ? count( $result['tabsEvents'] ) : null,
					'uploadCapEvents'         => $result['uploadCapEvents'] ?? array(),
					'postCapEvents'           => $result['postCapEvents'] ?? array(),
					'resolvedType'            => $result['resolvedType'] ?? null,
					'resolvedTab'             => $result['resolvedTab'] ?? null,
					'resolvedBodyId'          => $result['resolvedBodyId'] ?? null,
					'iframeRequestDefined'    => $result['iframeRequestDefined'] ?? null,
					'sourceBootstrapRemovals' => $result['sourceBootstrapRemovals'] ?? null,
					'assetStatus'             => $result['assetStatus'] ?? array(),
					'output'                  => self::describe_string( (string) ( $result['output'] ?? '' ) ),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_upload_entry_child_result_has_expected_shape( $result ),
				"{$case['label']} child evaluates media-upload.php entry source and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_upload_entry_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_media_upload_entry_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-media-upload-entry-dispatch',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_upload_entry_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, array $args, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$token = self::media_upload_dispatch_token( 'entry_' . $case_ctx->identifier( 4, 9 ) );

			return array_merge(
				array(
					'label'                    => $label,
					'seed'                     => $case_ctx->seed(),
					'iteration'                => $case_ctx->iteration(),
					'token'                    => $token,
					'allowUpload'              => true,
					'allowPostEdit'            => true,
					'requestType'              => null,
					'requestTab'               => null,
					'requestPostId'            => null,
					'requestInline'            => false,
					'localAction'              => null,
					'localId'                  => null,
					'localPostId'              => null,
					'defaultType'              => 'file',
					'defaultTab'               => 'type',
					'registerTab'              => null,
					'expectedHook'             => null,
					'expectedType'             => null,
					'expectedTab'              => null,
					'expectedBodyId'           => 'media-upload',
					'expectedIframe'           => true,
					'expectedEnqueued'         => true,
					'expectedReturned'         => true,
					'expectedDieText'          => null,
					'expectDefaultTypeFilter'  => false,
					'expectDefaultTabFilter'   => false,
					'expectTabsFilter'         => false,
					'expectedResolvedId'       => null,
					'expectedResolvedPostId'   => null,
					'expectPostCapabilityGate' => false,
					'expectedPostCapabilityAllowed' => null,
					'expectedPostCapabilityPostId'  => null,
					'applyUpdateGalleryTab'    => false,
					'seedGalleryAttachment'    => false,
					'expectedTabsContain'      => array(),
					'expectedTabsMissing'      => array(),
				),
				$args
			);
		};

		$unsafe_type = 'image</script><script>alert(4)</script>';

		return array(
			$build(
				'default-filters-select-type-action',
				array(
					'defaultType'             => 'video',
					'defaultTab'              => 'type',
					'expectedHook'            => 'media_upload_video',
					'expectedType'            => 'video',
					'expectedTab'             => 'type',
					'expectDefaultTypeFilter' => true,
					'expectDefaultTabFilter'  => true,
				),
				$ctx->fork( 'default-filters' )
			),
			$build(
				'registered-library-tab-dispatch',
				array(
					'requestType'            => 'image',
					'requestTab'             => 'library',
					'localPostId'            => '77</script><script>alert(1)</script>',
					'expectedHook'           => 'media_upload_library',
					'expectedType'           => 'image',
					'expectedTab'            => 'library',
					'expectTabsFilter'       => true,
					'expectedResolvedPostId' => 77,
				),
				$ctx->fork( 'library-tab' )
			),
			$build(
				'unknown-tab-falls-back-to-type',
				array(
					'requestType'        => 'audio',
					'requestTab'         => 'missing_component_tab',
					'expectedHook'       => 'media_upload_audio',
					'expectedType'       => 'audio',
					'expectedTab'        => 'missing_component_tab',
					'expectTabsFilter'   => true,
				),
				$ctx->fork( 'unknown-tab' )
			),
			$build(
				'custom-tab-dispatches-tab-action',
				array(
					'requestType'      => 'file',
					'requestTab'       => 'cfz_entry_tab',
					'registerTab'      => 'cfz_entry_tab',
					'expectedHook'     => 'media_upload_cfz_entry_tab',
					'expectedType'     => 'file',
					'expectedTab'      => 'cfz_entry_tab',
					'expectTabsFilter' => true,
				),
				$ctx->fork( 'custom-tab' )
			),
			$build(
				'type-url-dispatches-type-action',
				array(
					'requestType'  => 'file',
					'requestTab'   => 'type_url',
					'expectedHook' => 'media_upload_file',
					'expectedType' => 'file',
					'expectedTab'  => 'type_url',
				),
				$ctx->fork( 'type-url' )
			),
			$build(
				'inline-request-skips-iframe-constant',
				array(
					'requestType'     => 'image',
					'requestTab'      => 'type',
					'requestInline'   => true,
					'expectedHook'    => 'media_upload_image',
					'expectedType'    => 'image',
					'expectedTab'     => 'type',
					'expectedIframe'  => false,
				),
				$ctx->fork( 'inline' )
			),
			$build(
				'unsafe-type-unknown-tab-uses-raw-dynamic-hook',
				array(
					'requestType'      => $unsafe_type,
					'requestTab'       => 'unknown-unsafe-tab',
					'expectedHook'     => 'media_upload_' . $unsafe_type,
					'expectedType'     => $unsafe_type,
					'expectedTab'      => 'unknown-unsafe-tab',
					'expectTabsFilter' => true,
				),
				$ctx->fork( 'unsafe-type' )
			),
			$build(
				'gallery-without-post-id-falls-back-to-type',
				array(
					'requestType'           => 'image',
					'requestTab'            => 'gallery',
					'applyUpdateGalleryTab' => true,
					'expectedHook'          => 'media_upload_image',
					'expectedType'          => 'image',
					'expectedTab'           => 'gallery',
					'expectTabsFilter'      => true,
					'expectedTabsMissing'   => array( 'gallery' ),
				),
				$ctx->fork( 'gallery-no-post' )
			),
			$build(
				'gallery-with-attachments-dispatches-gallery-tab',
				array(
					'requestType'                   => 'image',
					'requestTab'                    => 'gallery',
					'applyUpdateGalleryTab'         => true,
					'seedGalleryAttachment'         => true,
					'expectedHook'                  => 'media_upload_gallery',
					'expectedType'                  => 'image',
					'expectedTab'                   => 'gallery',
					'expectTabsFilter'              => true,
					'expectPostCapabilityGate'      => true,
					'expectedPostCapabilityAllowed' => true,
					'expectedTabsContain'           => array( 'gallery' ),
				),
				$ctx->fork( 'gallery-with-attachment' )
			),
			$build(
				'upload-capability-denied',
				array(
					'allowUpload'      => false,
					'expectedReturned' => false,
					'expectedDieText'  => 'not allowed to upload files',
					'expectedEnqueued' => false,
					'expectedBodyId'   => null,
					'expectedIframe'   => true,
				),
				$ctx->fork( 'upload-denied' )
			),
			$build(
				'post-edit-capability-denied',
				array(
					'requestType'               => 'image',
					'requestTab'                => 'type',
					'requestPostId'             => '123</script><script>alert(2)</script>',
					'allowPostEdit'             => false,
					'expectedReturned'          => false,
					'expectedDieText'           => 'not allowed to edit this item',
					'expectedType'              => null,
					'expectedTab'               => null,
					'expectedBodyId'            => null,
					'expectPostCapabilityGate'  => true,
					'expectedPostCapabilityAllowed' => false,
					'expectedPostCapabilityPostId'  => 123,
				),
				$ctx->fork( 'post-denied' )
			),
			$build(
				'edit-action-missing-id-denied',
				array(
					'requestType'      => 'image',
					'requestTab'       => 'type',
					'localAction'      => 'edit',
					'expectedReturned' => false,
					'expectedDieText'  => 'Invalid item ID',
					'expectedType'     => null,
					'expectedTab'      => null,
					'expectedBodyId'   => null,
				),
				$ctx->fork( 'edit-missing-id' )
			),
		);
	}

	private static function run_media_upload_entry_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates media-upload.php top-level dispatch and wp_die branches in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_upload_entry_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_upload_entry_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_upload_entry_child( $case );
PHP;
	}

	public static function run_media_upload_entry_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		if ( function_exists( 'update_option' ) ) {
			\update_option( 'html_type', 'text/html' );
		}

		$ctx   = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$token = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state = array(
			'ok'                      => false,
			'label'                   => (string) ( $case['label'] ?? 'entry-dispatch' ),
			'token'                   => $token,
			'sourceBootstrapRemovals' => 0,
			'returned'                => false,
			'throwable'               => null,
			'output'                  => '',
			'contentBefore'           => self::media_url_insert_content_counts(),
			'contentAfter'            => array(),
			'assetStatus'             => array(),
			'actionEvents'            => array(),
			'defaultTypeEvents'       => array(),
			'defaultTabEvents'        => array(),
			'tabsEvents'              => array(),
			'uploadCapEvents'         => array(),
			'postCapEvents'           => array(),
			'dieCalls'                => array(),
			'resolvedType'            => null,
			'resolvedTab'             => null,
			'resolvedBodyId'          => null,
			'resolvedId'              => null,
			'resolvedPostId'          => null,
			'iframeRequestDefined'    => false,
			'headers'                 => array(),
			'galleryParentId'         => 0,
			'galleryAttachmentId'     => 0,
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']               = $output;
				$state['contentAfter']         = self::media_url_insert_content_counts();
				$state['assetStatus']          = self::media_upload_entry_asset_status();
				$state['iframeRequestDefined'] = defined( 'IFRAME_REQUEST' );
				$state['headers']              = function_exists( 'headers_list' ) ? headers_list() : array();
				$state['ok']                   = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$GLOBALS['pagenow']         = 'media-upload.php';
			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php';
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-upload-entry';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.49';
			$_SERVER['SERVER_PORT']     = '80';

			$request = array();
			if ( null !== ( $case['requestType'] ?? null ) ) {
				$request['type'] = (string) $case['requestType'];
			}
			if ( null !== ( $case['requestTab'] ?? null ) ) {
				$request['tab'] = (string) $case['requestTab'];
			}
			if ( null !== ( $case['requestPostId'] ?? null ) ) {
				$request['post_id'] = (string) $case['requestPostId'];
			}
			if ( ! empty( $case['requestInline'] ) ) {
				$request['inline'] = '1';
			}
			if ( ! empty( $case['seedGalleryAttachment'] ) ) {
				$gallery_parent     = self::seed_parent_post( $ctx->fork( 'gallery-parent' ) );
				$gallery_attachment = self::seed_attachment( $ctx->fork( 'gallery-attachment' ), 'image/jpeg', array( 'parent_id' => $gallery_parent ) );

				$state['galleryParentId']     = $gallery_parent;
				$state['galleryAttachmentId'] = (int) $gallery_attachment->ID;
				$request['post_id']           = (string) $gallery_parent;
			}

			$query = http_build_query( $request, '', '&', PHP_QUERY_RFC3986 );
			$_SERVER['REQUEST_URI'] = '/wp-admin/media-upload.php' . ( '' === $query ? '' : '?' . $query );
			$_GET                   = $request;
			$_POST                  = array();
			$_REQUEST               = $request;
			$_FILES                 = array();
			$_COOKIE                = array();

			if ( null !== ( $case['localAction'] ?? null ) ) {
				$action = (string) $case['localAction'];
			}
			if ( array_key_exists( 'localId', $case ) && null !== $case['localId'] ) {
				$ID = $case['localId']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
			if ( array_key_exists( 'localPostId', $case ) && null !== $case['localPostId'] ) {
				$post_id = $case['localPostId'];
			}

			$default_type_filter = static function ( string $type ) use ( &$state, $case ): string {
				$state['defaultTypeEvents'][] = array( 'input' => $type );
				return (string) ( $case['defaultType'] ?? $type );
			};
			$default_tab_filter  = static function ( string $tab ) use ( &$state, $case ): string {
				$state['defaultTabEvents'][] = array( 'input' => $tab );
				return (string) ( $case['defaultTab'] ?? $tab );
			};
			$tabs_filter         = static function ( array $tabs ) use ( &$state, $case ): array {
				$before = array_keys( $tabs );
				if ( null !== ( $case['registerTab'] ?? null ) ) {
					$tabs[ (string) $case['registerTab'] ] = 'Component Fuzz';
				}
				if ( ! empty( $case['applyUpdateGalleryTab'] ) ) {
					$tabs = \update_gallery_tab( $tabs );
				}
				$state['tabsEvents'][] = array(
					'before' => $before,
					'after'  => array_keys( $tabs ),
				);
				return $tabs;
			};
			$map_meta_cap_filter = static function ( array $caps, string $cap, int $user_id, array $args ) use ( &$state, $case ): array {
				if ( 'edit_post' !== $cap ) {
					return $caps;
				}

				$post_id = (int) ( $args[0] ?? 0 );
				$allowed = ! empty( $case['allowPostEdit'] );
				$state['postCapEvents'][] = array(
					'postId'  => $post_id,
					'allowed' => $allowed,
				);

				return $allowed ? array( 'exist' ) : array( 'do_not_allow' );
			};
			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ) use ( &$state, $case ): array {
				unset( $user );
				if ( in_array( 'upload_files', $caps, true ) ) {
					$allowed = ! empty( $case['allowUpload'] );
					$state['uploadCapEvents'][] = array(
						'allowed' => $allowed,
						'args'    => $args,
					);
					$allcaps['upload_files'] = $allowed;
				}

				foreach ( $caps as $cap ) {
					if ( 'upload_files' === $cap ) {
						continue;
					}
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}

				return $allcaps;
			};
			$die_handler_filter  = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};
			$entry_action        = static function () use ( &$state ): void {
				$state['actionEvents'][] = array(
					'hook'     => current_filter(),
					'didCount' => did_action( current_filter() ),
				);
			};

			\add_filter( 'media_upload_default_type', $default_type_filter, 10, 1 );
			\add_filter( 'media_upload_default_tab', $default_tab_filter, 10, 1 );
			\add_filter( 'media_upload_tabs', $tabs_filter, 10, 1 );
			\add_filter( 'map_meta_cap', $map_meta_cap_filter, 10, 4 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
			if ( is_string( $case['expectedHook'] ?? null ) && '' !== $case['expectedHook'] ) {
				\add_action( (string) $case['expectedHook'], $entry_action, 10, 0 );
			}

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array(
					'exist'        => true,
					'upload_files' => ! empty( $case['allowUpload'] ),
				);
			}

			foreach ( array( 'plupload-handlers', 'image-edit', 'set-post-thumbnail', 'media-gallery' ) as $handle ) {
				\wp_register_script( $handle, '/wp-admin/js/' . $handle . '.js', array(), false );
			}
			\wp_register_style( 'imgareaselect', '/wp-includes/js/imgareaselect/imgareaselect.css', array(), false );
			$state['contentBefore'] = self::media_url_insert_content_counts();

			$source_path = \ComponentFuzz\repo_root() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'wp-admin' . DIRECTORY_SEPARATOR . 'media-upload.php';
			$source      = file_get_contents( $source_path );
			if ( ! is_string( $source ) ) {
				throw new \RuntimeException( 'Could not read media-upload.php entry source.' );
			}

			$source = str_replace(
				"require_once __DIR__ . '/admin.php';",
				'/* component-fuzz skips the normal admin bootstrap; WpBootstrap already loaded a no-DB runtime. */',
				$source,
				$removals
			);
			$state['sourceBootstrapRemovals'] = $removals;
			if ( 1 !== $removals ) {
				throw new \RuntimeException( 'Could not isolate media-upload.php admin bootstrap include.' );
			}

			try {
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Evaluates the real media-upload.php entry source after removing only the normal admin bootstrap include.
				eval( '?>' . $source );
				$state['returned'] = true;
			} finally {
				if ( isset( $type ) ) {
					$state['resolvedType'] = (string) $type;
				}
				if ( isset( $tab ) ) {
					$state['resolvedTab'] = (string) $tab;
				}
				if ( isset( $body_id ) ) {
					$state['resolvedBodyId'] = (string) $body_id;
				}
				if ( isset( $ID ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$state['resolvedId'] = (int) $ID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				}
				if ( isset( $post_id ) ) {
					$state['resolvedPostId'] = (int) $post_id;
				}

				if ( is_string( $case['expectedHook'] ?? null ) && '' !== $case['expectedHook'] ) {
					\remove_action( (string) $case['expectedHook'], $entry_action, 10 );
				}
				\remove_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
				\remove_filter( 'map_meta_cap', $map_meta_cap_filter, 10 );
				\remove_filter( 'media_upload_tabs', $tabs_filter, 10 );
				\remove_filter( 'media_upload_default_tab', $default_tab_filter, 10 );
				\remove_filter( 'media_upload_default_type', $default_type_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_upload_entry_asset_status(): array {
		$wp_scripts = function_exists( 'wp_scripts' ) ? \wp_scripts() : null;
		$wp_styles  = function_exists( 'wp_styles' ) ? \wp_styles() : null;

		$scripts = array();
		foreach ( array( 'plupload-handlers', 'image-edit', 'set-post-thumbnail', 'media-gallery' ) as $handle ) {
			$scripts[ $handle ] = array(
				'enqueued'   => function_exists( 'wp_script_is' ) ? \wp_script_is( $handle, 'enqueued' ) : null,
				'registered' => function_exists( 'wp_script_is' ) ? \wp_script_is( $handle, 'registered' ) : null,
				'queued'     => is_object( $wp_scripts ) && property_exists( $wp_scripts, 'queue' ) && in_array( $handle, $wp_scripts->queue, true ),
			);
		}

		$styles = array();
		foreach ( array( 'imgareaselect' ) as $handle ) {
			$styles[ $handle ] = array(
				'enqueued'   => function_exists( 'wp_style_is' ) ? \wp_style_is( $handle, 'enqueued' ) : null,
				'registered' => function_exists( 'wp_style_is' ) ? \wp_style_is( $handle, 'registered' ) : null,
				'queued'     => is_object( $wp_styles ) && property_exists( $wp_styles, 'queue' ) && in_array( $handle, $wp_styles->queue, true ),
			);
		}

		return array(
			'scripts' => $scripts,
			'styles'  => $styles,
		);
	}

	private static function media_upload_entry_assets_match( array $asset_status, bool $expected_enqueued ): bool {
		foreach ( array( 'plupload-handlers', 'image-edit', 'set-post-thumbnail', 'media-gallery' ) as $handle ) {
			$observed = (bool) ( $asset_status['scripts'][ $handle ]['enqueued'] ?? false )
				|| (bool) ( $asset_status['scripts'][ $handle ]['queued'] ?? false );
			if ( $expected_enqueued !== $observed ) {
				return false;
			}
		}

		$style_observed = (bool) ( $asset_status['styles']['imgareaselect']['enqueued'] ?? false )
			|| (bool) ( $asset_status['styles']['imgareaselect']['queued'] ?? false );

		return $expected_enqueued === $style_observed;
	}

	private static function media_upload_entry_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null )
			&& is_array( $result['assetStatus'] ?? null )
			&& is_array( $result['actionEvents'] ?? null )
			&& is_array( $result['defaultTypeEvents'] ?? null )
			&& is_array( $result['defaultTabEvents'] ?? null )
			&& is_array( $result['tabsEvents'] ?? null )
			&& is_array( $result['uploadCapEvents'] ?? null )
			&& is_array( $result['postCapEvents'] ?? null )
			&& is_array( $result['dieCalls'] ?? null );
	}

	private static function collect_media_upload_entry_failures( array &$failures, array $case, array $result ): void {
		$output        = (string) ( $result['output'] ?? '' );
		$expected_hook = (string) ( $case['expectedHook'] ?? '' );
		$expects_die   = is_string( $case['expectedDieText'] ?? null );

		self::collect_failure(
			$failures,
			1 === (int) ( $result['sourceBootstrapRemovals'] ?? 0 )
				&& null === ( $result['throwable'] ?? null ),
			'media-upload.php entry source is evaluated after removing exactly the normal admin bootstrap include',
			array(
				'sourceBootstrapRemovals' => $result['sourceBootstrapRemovals'] ?? null,
				'throwable'               => $result['throwable'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			self::media_upload_entry_assets_match(
				is_array( $result['assetStatus'] ?? null ) ? $result['assetStatus'] : array(),
				! empty( $case['expectedEnqueued'] )
			),
			'entry file enqueues legacy media assets only after the upload_files gate passes',
			array(
				'expectedEnqueued' => ! empty( $case['expectedEnqueued'] ),
				'assetStatus'      => $result['assetStatus'] ?? array(),
			)
		);

		if ( $expects_die ) {
			$die_calls = is_array( $result['dieCalls'] ?? null ) ? $result['dieCalls'] : array();
			$die_text  = (string) ( $case['expectedDieText'] ?? '' );
			self::collect_failure(
				$failures,
				false === (bool) ( $result['returned'] ?? true )
					&& 1 === count( $die_calls )
					&& str_contains( (string) ( $die_calls[0]['message'] ?? '' ), $die_text )
					&& array() === ( $result['actionEvents'] ?? array() ),
				'entry capability and edit gates stop before dynamic media_upload_* dispatch',
				array(
					'expectedDieText' => $die_text,
					'dieCalls'        => $die_calls,
					'actionEvents'    => $result['actionEvents'] ?? array(),
					'returned'        => $result['returned'] ?? null,
				)
			);
		} else {
			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& array() === ( $result['dieCalls'] ?? array() )
					&& 1 === count( $result['actionEvents'] ?? array() )
					&& $expected_hook === (string) ( $result['actionEvents'][0]['hook'] ?? '' ),
				'entry dispatch reaches exactly the expected dynamic media_upload_* hook without wp_die',
				array(
					'expectedHook' => $expected_hook,
					'actionEvents' => $result['actionEvents'] ?? array(),
					'dieCalls'     => $result['dieCalls'] ?? array(),
					'returned'     => $result['returned'] ?? null,
				)
			);
		}

		self::collect_failure(
			$failures,
			(bool) ( $case['expectedIframe'] ?? true ) === (bool) ( $result['iframeRequestDefined'] ?? false ),
			'entry file defines IFRAME_REQUEST unless the inline request flag is present',
			array(
				'expectedIframe'        => $case['expectedIframe'] ?? true,
				'iframeRequestDefined' => $result['iframeRequestDefined'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			( $case['expectedType'] ?? null ) === ( $result['resolvedType'] ?? null )
				&& ( $case['expectedTab'] ?? null ) === ( $result['resolvedTab'] ?? null )
				&& ( $case['expectedBodyId'] ?? null ) === ( $result['resolvedBodyId'] ?? null ),
			'entry file resolves type, tab, and body ID according to request/default filter routing',
			array(
				'expectedType'   => $case['expectedType'] ?? null,
				'resolvedType'   => $result['resolvedType'] ?? null,
				'expectedTab'    => $case['expectedTab'] ?? null,
				'resolvedTab'    => $result['resolvedTab'] ?? null,
				'expectedBodyId' => $case['expectedBodyId'] ?? null,
				'resolvedBodyId' => $result['resolvedBodyId'] ?? null,
			)
		);

		if ( array_key_exists( 'expectedResolvedId', $case ) && null !== $case['expectedResolvedId'] ) {
			self::collect_failure(
				$failures,
				(int) $case['expectedResolvedId'] === (int) ( $result['resolvedId'] ?? -1 ),
				'entry edit gate casts the local ID before validating edit requests',
				array(
					'expectedResolvedId' => $case['expectedResolvedId'],
					'resolvedId'         => $result['resolvedId'] ?? null,
				)
			);
		}

		if ( array_key_exists( 'expectedResolvedPostId', $case ) && null !== $case['expectedResolvedPostId'] ) {
			self::collect_failure(
				$failures,
				(int) $case['expectedResolvedPostId'] === (int) ( $result['resolvedPostId'] ?? -1 ),
				'entry file casts the local post_id variable without leaking hostile bytes',
				array(
					'expectedResolvedPostId' => $case['expectedResolvedPostId'],
					'resolvedPostId'         => $result['resolvedPostId'] ?? null,
				)
			);
		}

		self::collect_failure(
			$failures,
			! empty( $case['expectDefaultTypeFilter'] ) === ( 1 === count( $result['defaultTypeEvents'] ?? array() ) )
				&& ! empty( $case['expectDefaultTabFilter'] ) === ( 1 === count( $result['defaultTabEvents'] ?? array() ) )
				&& ! empty( $case['expectTabsFilter'] ) === ( 1 === count( $result['tabsEvents'] ?? array() ) ),
			'entry routing applies default type/tab filters only when request values are absent and tab registry only when needed',
			array(
				'expectDefaultTypeFilter' => ! empty( $case['expectDefaultTypeFilter'] ),
				'defaultTypeEvents'       => $result['defaultTypeEvents'] ?? array(),
				'expectDefaultTabFilter'  => ! empty( $case['expectDefaultTabFilter'] ),
				'defaultTabEvents'        => $result['defaultTabEvents'] ?? array(),
				'expectTabsFilter'        => ! empty( $case['expectTabsFilter'] ),
				'tabsEvents'              => $result['tabsEvents'] ?? array(),
			)
		);

		if ( null !== ( $case['registerTab'] ?? null ) && isset( $result['tabsEvents'][0]['after'] ) ) {
			self::collect_failure(
				$failures,
				in_array( (string) $case['registerTab'], $result['tabsEvents'][0]['after'], true ),
				'custom media_upload_tabs entries participate in registered-tab dispatch',
				array(
					'registerTab' => $case['registerTab'],
					'tabsEvents'  => $result['tabsEvents'] ?? array(),
				)
			);
		}

		if ( isset( $result['tabsEvents'][0]['after'] ) ) {
			$tabs_after = is_array( $result['tabsEvents'][0]['after'] ) ? $result['tabsEvents'][0]['after'] : array();
			foreach ( (array) ( $case['expectedTabsContain'] ?? array() ) as $tab ) {
				self::collect_failure(
					$failures,
					in_array( (string) $tab, $tabs_after, true ),
					'media_upload_tabs filtering keeps expected registered tabs available for dispatch',
					array(
						'expectedTab' => $tab,
						'tabsEvents'  => $result['tabsEvents'] ?? array(),
					)
				);
			}
			foreach ( (array) ( $case['expectedTabsMissing'] ?? array() ) as $tab ) {
				self::collect_failure(
					$failures,
					! in_array( (string) $tab, $tabs_after, true ),
					'media_upload_tabs filtering removes unavailable registered tabs before dispatch fallback',
					array(
						'removedTab'  => $tab,
						'tabsEvents'  => $result['tabsEvents'] ?? array(),
					)
				);
			}
		}

		$post_cap_events = is_array( $result['postCapEvents'] ?? null ) ? $result['postCapEvents'] : array();
		$post_cap_ok     = array() === $post_cap_events;
		if ( ! empty( $case['expectPostCapabilityGate'] ) ) {
			$post_cap_allowed = $case['expectedPostCapabilityAllowed'] ?? null;
			$post_cap_id      = $case['expectedPostCapabilityPostId'] ?? null;
			if ( null === $post_cap_id && ! empty( $result['galleryParentId'] ) ) {
				$post_cap_id = (int) $result['galleryParentId'];
			}
			$post_cap_ok = 1 === count( $post_cap_events )
				&& ( null === $post_cap_allowed || (bool) $post_cap_allowed === (bool) ( $post_cap_events[0]['allowed'] ?? null ) )
				&& ( null === $post_cap_id || (int) $post_cap_id === (int) ( $post_cap_events[0]['postId'] ?? 0 ) );
		}

		self::collect_failure(
			$failures,
			1 === count( $result['uploadCapEvents'] ?? array() )
				&& ! empty( $case['allowUpload'] ) === (bool) ( $result['uploadCapEvents'][0]['allowed'] ?? false )
				&& $post_cap_ok,
			'entry file checks upload_files first and checks edit_post only for non-empty request post_id',
			array(
				'allowUpload'                   => $case['allowUpload'] ?? null,
				'uploadCapEvents'              => $result['uploadCapEvents'] ?? array(),
				'expectPostCapabilityGate'      => ! empty( $case['expectPostCapabilityGate'] ),
				'expectedPostCapabilityAllowed' => $case['expectedPostCapabilityAllowed'] ?? null,
				'expectedPostCapabilityPostId'  => $case['expectedPostCapabilityPostId'] ?? null,
				'galleryParentId'              => $result['galleryParentId'] ?? null,
				'postCapEvents'                => $post_cap_events,
			)
		);

		self::collect_failure(
			$failures,
			'' === $output
				&& ! str_contains( $output, '</script><script>' )
				&& ( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() ),
			'entry dispatch does not emit body output, leak hostile request script bytes, or mutate content rows',
			array(
				'output'        => self::describe_string( $output ),
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
			)
		);
	}

	private static function check_media_library_gallery_iframe_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-library-gallery-iframe-rendering',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_library_gallery_cases( $ctx ) as $case ) {
			$run    = self::run_media_library_gallery_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'          => $result['returned'] ?? null,
					'returnType'        => $result['returnType'] ?? null,
					'throwable'         => $result['throwable'] ?? null,
					'output'            => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'formUrlEvents'     => $result['formUrlEvents'] ?? array(),
					'fieldEventCount'   => is_array( $result['fieldEvents'] ?? null ) ? count( $result['fieldEvents'] ) : null,
					'mimeLinkCount'     => is_array( $result['mimeLinkEvents'] ?? null ) ? count( $result['mimeLinkEvents'] ) : null,
					'getMediaItemCount' => is_array( $result['getMediaItemArgsEvents'] ?? null ) ? count( $result['getMediaItemArgsEvents'] ) : null,
					'queryPostIds'      => $result['queryPostIds'] ?? array(),
					'expectedIds'       => $result['expectedIds'] ?? array(),
					'absentIds'         => $result['absentIds'] ?? array(),
					'iframeActions'     => $result['iframeActionCounts'] ?? array(),
					'scriptStatus'      => $result['scriptStatus'] ?? array(),
					'saveEventCount'    => is_array( $result['saveEvents'] ?? null ) ? count( $result['saveEvents'] ) : null,
					'sendEventCount'    => is_array( $result['sendEvents'] ?? null ) ? count( $result['sendEvents'] ) : null,
					'dieCalls'          => $result['dieCalls'] ?? array(),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_library_gallery_child_result_has_expected_shape( $result ),
				"{$case['label']} child renders legacy library/gallery iframe and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_library_gallery_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& 'NULL' === (string) ( $result['returnType'] ?? '' )
					&& null === ( $result['throwable'] ?? null )
					&& array() === ( $result['dieCalls'] ?? array() ),
				"{$case['label']} returns normally after wp_iframe() without wp_die or unexpected exceptions",
				array(
					'returned'  => $result['returned'] ?? null,
					'returnType' => $result['returnType'] ?? null,
					'throwable' => $result['throwable'] ?? null,
					'dieCalls'  => $result['dieCalls'] ?? array(),
				)
			);

			self::collect_media_library_gallery_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-library-gallery-iframe-rendering',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_library_gallery_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, string $scenario, array $args, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$token = self::media_upload_dispatch_token( 'libgal_' . $case_ctx->identifier( 4, 9 ) );

			return array_merge(
				array(
					'label'       => $label,
					'scenario'    => $scenario,
					'seed'        => $case_ctx->seed(),
					'iteration'   => $case_ctx->iteration(),
					'token'       => $token,
					'type'        => 'image',
					'chromeless'  => false,
					'requestArgs' => array(),
				),
				$args
			);
		};

		return array(
			$build(
				'library-image-page-two-search',
				'library',
				array(
					'requestArgs' => array(
						'post_mime_type' => 'image',
						'paged'          => '2',
						's'              => 'Library',
						'context'        => 'display</script><script>alert(1)</script>',
					),
				),
				$ctx->fork( 'library-page-two' )
			),
			$build(
				'library-invalid-paged-all-types',
				'library',
				array(
					'type'        => 'file',
					'requestArgs' => array(
						'post_mime_type' => 'all',
						'paged'          => '-9</script><script>alert(2)</script>',
						's'              => 'Library',
					),
				),
				$ctx->fork( 'library-invalid-paged' )
			),
			$build(
				'gallery-parent-get',
				'gallery-parent',
				array(),
				$ctx->fork( 'gallery-parent' )
			),
			$build(
				'gallery-attachment-get-chromeless',
				'gallery-attachment',
				array( 'chromeless' => true ),
				$ctx->fork( 'gallery-attachment' )
			),
		);
	}

	private static function run_media_library_gallery_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates legacy media library/gallery iframe output in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_library_gallery_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_library_gallery_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_library_gallery_child( $case );
PHP;
	}

	public static function run_media_library_gallery_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx      = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$scenario = (string) ( $case['scenario'] ?? 'library' );
		$token    = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$type     = (string) ( $case['type'] ?? 'image' );
		$tab      = 'library' === $scenario ? 'library' : 'gallery';
		$state    = array(
			'ok'                     => false,
			'label'                  => (string) ( $case['label'] ?? 'library-gallery' ),
			'scenario'               => $scenario,
			'token'                  => $token,
			'type'                   => $type,
			'tab'                    => $tab,
			'postId'                 => 0,
			'parentId'               => 0,
			'libraryImageIds'        => array(),
			'libraryOtherIds'        => array(),
			'expectedIds'            => array(),
			'absentIds'              => array(),
			'queryPostIds'           => array(),
			'queryVars'              => array(),
			'foundPosts'             => null,
			'contentBefore'          => array(),
			'contentAfter'           => array(),
			'formUrlEvents'          => array(),
			'fieldEvents'            => array(),
			'mimeLinkEvents'         => array(),
			'getMediaItemArgsEvents' => array(),
			'saveEvents'             => array(),
			'sendEvents'             => array(),
			'dieCalls'               => array(),
			'iframeActionCounts'     => array(),
			'adminEnqueueArgs'       => array(),
			'scriptStatus'           => array(),
			'returned'               => false,
			'returnType'             => null,
			'throwable'              => null,
			'output'                 => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$wp_the_query = $GLOBALS['wp_the_query'] ?? null;
				if ( is_object( $wp_the_query ) ) {
					$posts = is_array( $wp_the_query->posts ?? null ) ? $wp_the_query->posts : array();
					$state['queryPostIds'] = array_map(
						static function ( $post ): int {
							return (int) ( $post->ID ?? 0 );
						},
						$posts
					);
					$state['queryVars']  = is_array( $wp_the_query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $wp_the_query->query_vars ) : array();
					$state['foundPosts'] = isset( $wp_the_query->found_posts ) ? (int) $wp_the_query->found_posts : null;
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['scriptStatus'] = self::media_gallery_save_script_status( 'admin-gallery' );
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$parent_id = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$image_ids = array();
			for ( $i = 0; $i < 12; ++$i ) {
				$image = self::seed_attachment(
					$ctx->fork( 'library-image-' . $i ),
					'image/jpeg',
					array(
						'parent_id'  => $parent_id,
						'post_title' => 'Library image ' . $i . ' <script>alert(1)</script> ' . $token,
						'alt'        => 'Library alt ' . $i . ' <script>alert(1)</script> ' . $token,
					)
				);
				$image_ids[] = (int) $image->ID;
			}

			$pdf = self::seed_attachment(
				$ctx->fork( 'library-pdf' ),
				'application/pdf',
				array(
					'parent_id'  => $parent_id,
					'post_title' => 'Library PDF <script>alert(1)</script> ' . $token,
				)
			);
			$audio = self::seed_attachment(
				$ctx->fork( 'library-audio' ),
				'audio/mpeg',
				array(
					'parent_id'  => $parent_id,
					'post_title' => 'Library audio <script>alert(1)</script> ' . $token,
				)
			);
			$other_ids = array( (int) $pdf->ID, (int) $audio->ID );

			$post_id = $parent_id;
			if ( 'gallery-attachment' === $scenario ) {
				$post_id = $image_ids[0];
			}

			$state['postId']          = $post_id;
			$state['parentId']        = $parent_id;
			$state['libraryImageIds'] = $image_ids;
			$state['libraryOtherIds'] = $other_ids;
			$state['contentBefore']   = self::media_url_insert_content_counts();

			$GLOBALS['pagenow']      = 'media-upload.php';
			$GLOBALS['type']         = $type;
			$GLOBALS['tab']          = $tab;
			$GLOBALS['body_id']      = 'component-fuzz-library-gallery';
			$GLOBALS['wp']           = new \WP();
			$GLOBALS['wp_query']     = new \WP_Query();
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=' . rawurlencode( $type ) . '&tab=' . rawurlencode( $tab );
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-library-gallery';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.50';
			$_SERVER['SERVER_PORT']     = '80';

			$request = array_merge(
				array(
					'type'    => $type,
					'tab'     => $tab,
					'post_id' => (string) $post_id,
				),
				is_array( $case['requestArgs'] ?? null ) ? $case['requestArgs'] : array()
			);
			if ( ! empty( $case['chromeless'] ) ) {
				$request['chromeless'] = '1';
			}
			$query = http_build_query( $request, '', '&', PHP_QUERY_RFC3986 );
			$_SERVER['REQUEST_URI'] = '/wp-admin/media-upload.php?' . $query;
			$_GET                   = $request;
			$_POST                  = array();
			$_REQUEST               = $request;
			$_FILES                 = array();
			$_COOKIE                = array();

			\wp_register_script( 'admin-gallery', '/wp-admin/js/gallery.js', array(), false );

			$form_url_filter = static function ( string $url, string $url_type ) use ( &$state, $token ): string {
				$state['formUrlEvents'][] = array(
					'url'  => $url,
					'type' => $url_type,
				);
				return \add_query_arg( 'cfz_library_gallery', $token, $url );
			};
			$fields_filter   = static function ( array $fields, \WP_Post $post ) use ( &$state, $token ): array {
				$state['fieldEvents'][] = array(
					'id'       => (int) $post->ID,
					'title'    => (string) $post->post_title,
					'mimeType' => (string) $post->post_mime_type,
				);
				$fields['component_fuzz_library_gallery'] = array(
					'label' => 'Component Fuzz Library Gallery',
					'value' => 'Library gallery field <script>alert(1)</script> ' . $token,
				);
				return $fields;
			};
			$mime_links_filter = static function ( array $links ) use ( &$state, $token ): array {
				$state['mimeLinkEvents'][] = array(
					'count' => count( $links ),
					'html'  => implode( '|', $links ),
				);
				$links[] = '<li><a id="cfz-mime-' . esc_attr( $token ) . '" href="#">Component Fuzz</a>';
				return $links;
			};
			$item_args_filter = static function ( array $args ) use ( &$state ): array {
				$state['getMediaItemArgsEvents'][] = $args;
				return $args;
			};
			$upload_per_page_filter = static function (): int {
				return 10;
			};
			$save_filter = static function ( array $post, array $attachment ) use ( &$state ): array {
				$state['saveEvents'][] = array(
					'id'    => (int) ( $post['ID'] ?? 0 ),
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $post;
			};
			$send_filter = static function ( string $html, int $send_id, array $attachment ) use ( &$state ): string {
				$state['sendEvents'][] = array(
					'id'    => $send_id,
					'html'  => $html,
					'title' => (string) ( $attachment['post_title'] ?? '' ),
				);
				return $html;
			};
			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$die_handler_filter = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};
			$iframe_hooks = array(
				'admin_enqueue_scripts',
				'admin_print_styles-media-upload-popup',
				'admin_print_styles',
				'admin_print_scripts-media-upload-popup',
				'admin_print_scripts',
				'admin_head-media-upload-popup',
				'admin_head',
				'admin_print_footer_scripts',
				'library' === $scenario ? 'admin_head_media_upload_library_form' : 'admin_head_media_upload_gallery_form',
			);
			$iframe_action_callbacks = array();
			foreach ( $iframe_hooks as $hook ) {
				$state['iframeActionCounts'][ $hook ] = 0;
				$iframe_action_callbacks[ $hook ] = static function ( $arg = null ) use ( &$state, $hook ): void {
					++$state['iframeActionCounts'][ $hook ];
					if ( 'admin_enqueue_scripts' === $hook ) {
						$state['adminEnqueueArgs'][] = $arg;
					}
				};
			}

			\add_filter( 'media_upload_form_url', $form_url_filter, 10, 2 );
			\add_filter( 'attachment_fields_to_edit', $fields_filter, 11, 2 );
			\add_filter( 'media_upload_mime_type_links', $mime_links_filter, 10, 1 );
			\add_filter( 'get_media_item_args', $item_args_filter, 10, 1 );
			\add_filter( 'upload_per_page', $upload_per_page_filter, 10, 0 );
			\add_filter( 'attachment_fields_to_save', $save_filter, 10, 2 );
			\add_filter( 'media_send_to_editor', $send_filter, 10, 3 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
			foreach ( $iframe_action_callbacks as $hook => $callback ) {
				\add_action( $hook, $callback, 10, 1 );
			}

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			try {
				$return_value = 'library' === $scenario ? \media_upload_library() : \media_upload_gallery();
				$state['returnType'] = gettype( $return_value );
				$state['returned']   = true;
			} finally {
				foreach ( $iframe_action_callbacks as $hook => $callback ) {
					\remove_action( $hook, $callback, 10 );
				}
				\remove_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
				\remove_filter( 'media_send_to_editor', $send_filter, 10 );
				\remove_filter( 'attachment_fields_to_save', $save_filter, 10 );
				\remove_filter( 'upload_per_page', $upload_per_page_filter, 10 );
				\remove_filter( 'get_media_item_args', $item_args_filter, 10 );
				\remove_filter( 'media_upload_mime_type_links', $mime_links_filter, 10 );
				\remove_filter( 'attachment_fields_to_edit', $fields_filter, 11 );
				\remove_filter( 'media_upload_form_url', $form_url_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_library_gallery_query_summary( array $query_vars ): array {
		$keys = array( 'post_type', 'post_status', 'post_mime_type', 'posts_per_page', 'paged', 'offset', 's', 'm', 'post_parent', 'author', 'date_query' );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $query_vars ) ) {
				$out[ $key ] = $query_vars[ $key ];
			}
		}
		return $out;
	}

	private static function media_library_gallery_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null )
			&& is_array( $result['formUrlEvents'] ?? null )
			&& is_array( $result['fieldEvents'] ?? null )
			&& is_array( $result['mimeLinkEvents'] ?? null )
			&& is_array( $result['getMediaItemArgsEvents'] ?? null )
			&& is_array( $result['queryPostIds'] ?? null )
			&& is_array( $result['queryVars'] ?? null )
			&& is_array( $result['saveEvents'] ?? null )
			&& is_array( $result['sendEvents'] ?? null )
			&& is_array( $result['dieCalls'] ?? null )
			&& is_array( $result['iframeActionCounts'] ?? null )
			&& is_array( $result['adminEnqueueArgs'] ?? null )
			&& is_array( $result['scriptStatus'] ?? null );
	}

	private static function collect_media_library_gallery_failures( array &$failures, array $case, array $result ): void {
		$output      = (string) ( $result['output'] ?? '' );
		$scenario    = (string) ( $case['scenario'] ?? '' );
		$is_library  = 'library' === $scenario;
		$token       = self::media_upload_dispatch_token( (string) ( $result['token'] ?? $case['token'] ?? '' ) );
		$dynamic_hook = $is_library ? 'admin_head_media_upload_library_form' : 'admin_head_media_upload_gallery_form';

		self::collect_failure(
			$failures,
			str_contains( $output, 'pagenow = \'media-upload-popup\'' )
				&& str_contains( $output, '<body id="component-fuzz-library-gallery" class="wp-core-ui no-js ' )
				&& str_contains( $output, '</html>' )
				&& str_contains( $output, 'cfz_library_gallery=' . rawurlencode( $token ) )
				&& ! str_contains( $output, '</script><script>alert(' ),
			'legacy library/gallery callback renders a complete iframe shell with the filtered form action token and escaped hostile request bytes',
			array(
				'output' => self::describe_string( $output ),
				'token'  => $token,
			)
		);

		$iframe_hooks = array(
			'admin_enqueue_scripts',
			'admin_print_styles-media-upload-popup',
			'admin_print_styles',
			'admin_print_scripts-media-upload-popup',
			'admin_print_scripts',
			'admin_head-media-upload-popup',
			'admin_head',
			'admin_print_footer_scripts',
			$dynamic_hook,
		);
		$iframe_counts = is_array( $result['iframeActionCounts'] ?? null ) ? $result['iframeActionCounts'] : array();
		$iframe_hooks_ok = true;
		foreach ( $iframe_hooks as $hook ) {
			$iframe_hooks_ok = $iframe_hooks_ok && 1 === (int) ( $iframe_counts[ $hook ] ?? 0 );
		}

		self::collect_failure(
			$failures,
			$iframe_hooks_ok && array( 'media-upload-popup' ) === ( $result['adminEnqueueArgs'] ?? array() ),
			'wp_iframe() fires the expected generic and callback-specific popup hooks',
			array(
				'iframeActionCounts' => $iframe_counts,
				'adminEnqueueArgs'   => $result['adminEnqueueArgs'] ?? array(),
				'dynamicHook'        => $dynamic_hook,
			)
		);

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() )
				&& array() === ( $result['saveEvents'] ?? array() )
				&& array() === ( $result['sendEvents'] ?? array() ),
			'GET library/gallery iframe rendering does not mutate content or enter save/send-to-editor paths',
			array(
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
				'saveEvents'    => $result['saveEvents'] ?? array(),
				'sendEvents'    => $result['sendEvents'] ?? array(),
			)
		);

		if ( $is_library ) {
			self::collect_media_library_iframe_failures( $failures, $case, $result, $output, $token );
		} else {
			self::collect_media_gallery_get_iframe_failures( $failures, $case, $result, $output );
		}
	}

	private static function collect_media_library_iframe_failures( array &$failures, array $case, array $result, string $output, string $token ): void {
		$query_ids  = array_values( array_filter( array_map( 'intval', $result['queryPostIds'] ?? array() ) ) );
		$field_ids  = array_map(
			static function ( array $event ): int {
				return (int) ( $event['id'] ?? 0 );
			},
			$result['fieldEvents'] ?? array()
		);
		$other_ids  = array_values( array_map( 'intval', $result['libraryOtherIds'] ?? array() ) );
		$image_ids  = array_values( array_map( 'intval', $result['libraryImageIds'] ?? array() ) );
		$request    = is_array( $case['requestArgs'] ?? null ) ? $case['requestArgs'] : array();
		$query_vars = is_array( $result['queryVars'] ?? null ) ? $result['queryVars'] : array();

		$rendered_query_ids = true;
		foreach ( $query_ids as $id ) {
			$rendered_query_ids = $rendered_query_ids
				&& str_contains( $output, "id='media-item-$id'" )
				&& str_contains( $output, "attachments[$id][component_fuzz_library_gallery]" );
		}
		$other_ids_absent = true;
		foreach ( $other_ids as $id ) {
			$other_ids_absent = $other_ids_absent && ! str_contains( $output, "id='media-item-$id'" );
		}

		self::collect_failure(
			$failures,
			str_contains( $output, 'id="filter"' )
				&& str_contains( $output, 'id="library-form"' )
				&& str_contains( $output, 'id="media-search-input"' )
				&& str_contains( $output, '<ul class="subsubsub">' )
				&& str_contains( $output, 'id="cfz-mime-' . $token . '"' )
				&& ! str_contains( $output, 'id="gallery-form"' )
				&& ! str_contains( $output, 'id="gallery-settings"' ),
			'library tab renders search/filter chrome, MIME links, and library form without gallery-only controls',
			array( 'output' => self::describe_string( $output ) )
		);

		self::collect_failure(
			$failures,
			1 === count( $result['formUrlEvents'] ?? array() )
				&& (string) ( $result['type'] ?? '' ) === (string) ( $result['formUrlEvents'][0]['type'] ?? '' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'tab=library' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'post_id=' . (string) ( $result['postId'] ?? 0 ) ),
			'library form action receives the current media type and cast post ID through media_upload_form_url',
			array( 'formUrlEvents' => $result['formUrlEvents'] ?? array() )
		);

		self::collect_failure(
			$failures,
			$query_ids !== array()
				&& $query_ids === $field_ids
				&& count( $query_ids ) <= 10
				&& $rendered_query_ids
				&& $other_ids_absent,
			'library tab renders exactly the paged attachment query result and excludes non-matching MIME rows',
			array(
				'queryPostIds' => $query_ids,
				'fieldIds'     => $field_ids,
				'otherIds'     => $other_ids,
				'output'       => self::describe_string( $output ),
			)
		);

		if ( 'image' === ( $request['post_mime_type'] ?? null ) ) {
			self::collect_failure(
				$failures,
				array_diff( $query_ids, $image_ids ) === array()
					&& ! array_intersect( $query_ids, $other_ids ),
				'library image MIME filter confines the query result to seeded image attachments',
				array(
					'queryPostIds' => $query_ids,
					'imageIds'     => $image_ids,
					'otherIds'     => $other_ids,
				)
			);
		}

		self::collect_failure(
			$failures,
			10 === (int) ( $query_vars['posts_per_page'] ?? 0 )
				&& ( isset( $request['paged'] ) && (int) $request['paged'] < 1 ? 1 : max( 1, (int) ( $request['paged'] ?? 1 ) ) ) === (int) ( $query_vars['paged'] ?? 0 )
				&& 'attachment' === (string) ( $query_vars['post_type'] ?? '' )
				&& str_contains( $output, 'tablenav-pages' ),
			'library request normalizes pagination/query vars and emits pagination controls for the generated attachment set',
			array(
				'requestArgs' => $request,
				'queryVars'   => $query_vars,
				'foundPosts'  => $result['foundPosts'] ?? null,
				'output'      => self::describe_string( $output ),
			)
		);

		$context_ok = ! isset( $request['context'] )
			|| (
				! str_contains( $output, 'display</script><script>' )
				&& str_contains( $output, 'display&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;' )
			);

		self::collect_failure(
			$failures,
			str_contains( $output, 'value="Library"' )
				&& $context_ok,
			'library search and hidden context request values are escaped in filter controls',
			array(
				'requestArgs' => $request,
				'output'      => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $result['mimeLinkEvents'] ?? array() )
				&& count( $result['getMediaItemArgsEvents'] ?? array() ) === count( $query_ids ),
			'library tab applies MIME-link and media-item argument filters once per rendered query page',
			array(
				'mimeLinkEvents'         => $result['mimeLinkEvents'] ?? array(),
				'getMediaItemArgsEvents' => $result['getMediaItemArgsEvents'] ?? array(),
				'queryPostIds'           => $query_ids,
			)
		);
	}

	private static function collect_media_gallery_get_iframe_failures( array &$failures, array $case, array $result, string $output ): void {
		$expected_ids = 'gallery-attachment' === (string) ( $case['scenario'] ?? '' )
			? array( (int) ( $result['postId'] ?? 0 ) )
			: array_merge(
				array_values( array_map( 'intval', $result['libraryImageIds'] ?? array() ) ),
				array_values( array_map( 'intval', $result['libraryOtherIds'] ?? array() ) )
			);
		$expected_ids = array_values( array_filter( $expected_ids ) );
		$field_ids    = array_map(
			static function ( array $event ): int {
				return (int) ( $event['id'] ?? 0 );
			},
			$result['fieldEvents'] ?? array()
		);
		sort( $expected_ids );
		sort( $field_ids );

		$expected_markup = true;
		foreach ( $expected_ids as $id ) {
			$expected_markup = $expected_markup
				&& str_contains( $output, "id='media-item-$id'" )
				&& str_contains( $output, "attachments[$id][menu_order]" )
				&& str_contains( $output, "attachments[$id][component_fuzz_library_gallery]" );
		}

		self::collect_failure(
			$failures,
			str_contains( $output, 'id="gallery-form"' )
				&& str_contains( $output, 'id="sort-buttons"' )
				&& str_contains( $output, 'id="gallery-settings"' )
				&& str_contains( $output, 'id="insert-gallery"' )
				&& str_contains( $output, 'id="save-all"' )
				&& ! str_contains( $output, 'id="library-form"' )
				&& ! str_contains( $output, 'id="media-search-input"' )
				&& $expected_markup,
			'gallery GET tab renders gallery sorting/settings controls and the expected attachment set',
			array(
				'expectedIds' => $expected_ids,
				'fieldIds'    => $field_ids,
				'output'      => self::describe_string( $output ),
			)
		);

		self::collect_failure(
			$failures,
			$expected_ids === $field_ids
				&& 1 === count( $result['formUrlEvents'] ?? array() )
				&& 'image' === (string) ( $result['formUrlEvents'][0]['type'] ?? '' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'tab=gallery' )
				&& str_contains( (string) ( $result['formUrlEvents'][0]['url'] ?? '' ), 'post_id=' . (string) ( $result['postId'] ?? 0 ) ),
			'gallery GET tab routes form URL and attachment edit fields for the selected parent or attachment',
			array(
				'formUrlEvents' => $result['formUrlEvents'] ?? array(),
				'fieldEvents'   => $result['fieldEvents'] ?? array(),
				'expectedIds'   => $expected_ids,
			)
		);

		$script_status = $result['scriptStatus'] ?? array();
		self::collect_failure(
			$failures,
			true === ( $script_status['enqueued'] ?? false )
				|| true === ( $script_status['to_do'] ?? false )
				|| true === ( $script_status['done'] ?? false ),
			'gallery GET tab enqueues the legacy admin-gallery script handle before rendering',
			array( 'scriptStatus' => $script_status )
		);

		self::collect_failure(
			$failures,
			empty( $case['chromeless'] )
				? str_contains( $output, 'id="media-upload-header"' )
				: ! str_contains( $output, 'id="media-upload-header"' ),
			'gallery GET tab honors the chromeless request flag in media_upload_header()',
			array(
				'chromeless' => $case['chromeless'] ?? false,
				'output'    => self::describe_string( $output ),
			)
		);
	}

	private static function check_media_library_query_date_filters( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-library-query-date-filters',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_library_query_date_cases( $ctx ) as $case ) {
			$run    = self::run_media_library_query_date_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'            => $result['returned'] ?? null,
					'returnType'          => $result['returnType'] ?? null,
					'throwable'           => $result['throwable'] ?? null,
					'output'              => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					'monthRows'           => $result['monthRows'] ?? array(),
					'queries'             => $result['queries'] ?? array(),
					'filenameFilterAfter' => $result['filenameFilterAfter'] ?? null,
					'contentBefore'       => $result['contentBefore'] ?? array(),
					'contentAfter'        => $result['contentAfter'] ?? array(),
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_library_query_date_child_result_has_expected_shape( $result ),
				"{$case['label']} child exercises media library query/date filters and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_library_query_date_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& 'NULL' === (string) ( $result['returnType'] ?? '' )
					&& null === ( $result['throwable'] ?? null ),
				"{$case['label']} renders the library form normally after query filter probes",
				array(
					'returned'  => $result['returned'] ?? null,
					'returnType' => $result['returnType'] ?? null,
					'throwable' => $result['throwable'] ?? null,
				)
			);

			self::collect_media_library_query_date_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-library-query-date-filters',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_library_query_date_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			array(
				'label'     => 'filename-date-detached-mine',
				'seed'      => $ctx->seed(),
				'iteration' => $ctx->iteration(),
				'token'     => self::media_upload_dispatch_token( 'mqdf_' . $ctx->identifier( 4, 9 ) ),
			),
		);
	}

	private static function run_media_library_query_date_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates media-library query globals and wp_allow_query_attachment_by_filename cleanup in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_library_query_date_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_library_query_date_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_library_query_date_child( $case );
PHP;
	}

	public static function run_media_library_query_date_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx   = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$token = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state = array(
			'ok'                     => false,
			'label'                  => (string) ( $case['label'] ?? 'media-library-query-date' ),
			'token'                  => $token,
			'filenameTerm'           => 'filename-' . $token,
			'currentUserId'          => 0,
			'parentId'               => 0,
			'filenameMatchIds'       => array(),
			'filenameExpectedPage2'  => array(),
			'outsideMonthIds'        => array(),
			'detachedIds'            => array(),
			'attachedControlIds'     => array(),
			'mineIds'                => array(),
			'otherAuthorIds'         => array(),
			'monthRows'              => array(),
			'queries'                => array(),
			'filenameFilterBefore'   => null,
			'filenameFilterAfter'    => null,
			'contentBefore'          => array(),
			'contentAfter'           => array(),
			'returned'               => false,
			'returnType'             => null,
			'throwable'              => null,
			'output'                 => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$parent_id = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$state['parentId'] = $parent_id;

			$current_user_id = \wp_insert_user(
				array(
					'user_login' => 'media-query-date-' . substr( hash( 'crc32b', $token ), 0, 8 ),
					'user_pass'  => 'component-fuzz-password',
					'user_email' => 'media-query-date-' . substr( hash( 'crc32b', $token ), 0, 8 ) . '@example.test',
					'role'       => 'administrator',
				)
			);
			if ( ! is_int( $current_user_id ) || $current_user_id <= 0 ) {
				throw new \RuntimeException( 'Could not seed current user for media library mine filter.' );
			}

			$state['currentUserId'] = $current_user_id;
			\wp_set_current_user( $current_user_id );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$upload_per_page_filter = static function (): int {
				return 5;
			};

			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'upload_per_page', $upload_per_page_filter, 10, 0 );

			try {
				for ( $i = 0; $i < 7; ++$i ) {
					$day        = 20 - $i;
					$date       = sprintf( '2026-06-%02d 10:00:00', $day );
					$attachment = self::seed_attachment(
						$ctx->fork( 'filename-june-' . $i ),
						'image/jpeg',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => 2,
							'post_date'     => $date,
							'post_date_gmt' => $date,
							'post_title'    => 'June attachment ' . $i . ' ' . $token,
							'relative_file' => '2026/06/' . $state['filenameTerm'] . '-' . $i . '.jpg',
						)
					);
					$state['filenameMatchIds'][] = (int) $attachment->ID;
				}
				$state['filenameExpectedPage2'] = array_slice( $state['filenameMatchIds'], 5, 2 );

				$may = self::seed_attachment(
					$ctx->fork( 'filename-may' ),
					'image/jpeg',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-05-22 10:00:00',
						'post_date_gmt' => '2026-05-22 10:00:00',
						'post_title'    => 'May attachment outside date ' . $token,
						'relative_file' => '2026/05/' . $state['filenameTerm'] . '-outside.jpg',
					)
				);
				$state['outsideMonthIds'][] = (int) $may->ID;

				for ( $i = 0; $i < 3; ++$i ) {
					$date       = sprintf( '2026-07-%02d 09:00:00', 10 - $i );
					$attachment = self::seed_attachment(
						$ctx->fork( 'detached-' . $i ),
						'image/png',
						array(
							'parent_id'     => 0,
							'post_author'   => 2,
							'post_date'     => $date,
							'post_date_gmt' => $date,
							'post_title'    => 'Detached image ' . $i . ' ' . $token,
							'relative_file' => '2026/07/detached-' . $token . '-' . $i . '.png',
						)
					);
					$state['detachedIds'][] = (int) $attachment->ID;
				}

				$attached_control = self::seed_attachment(
					$ctx->fork( 'attached-control' ),
					'image/png',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-07-11 09:00:00',
						'post_date_gmt' => '2026-07-11 09:00:00',
						'post_title'    => 'Attached control ' . $token,
						'relative_file' => '2026/07/attached-control-' . $token . '.png',
					)
				);
				$state['attachedControlIds'][] = (int) $attached_control->ID;

				for ( $i = 0; $i < 3; ++$i ) {
					$date       = sprintf( '2026-04-%02d 08:00:00', 10 - $i );
					$attachment = self::seed_attachment(
						$ctx->fork( 'mine-' . $i ),
						'application/pdf',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => $current_user_id,
							'post_date'     => $date,
							'post_date_gmt' => $date,
							'post_title'    => 'Mine PDF ' . $i . ' ' . $token,
							'relative_file' => '2026/04/mine-' . $token . '-' . $i . '.pdf',
						)
					);
					$state['mineIds'][] = (int) $attachment->ID;
				}

				$other_author = self::seed_attachment(
					$ctx->fork( 'other-author' ),
					'application/pdf',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-04-11 08:00:00',
						'post_date_gmt' => '2026-04-11 08:00:00',
						'post_title'    => 'Other author PDF ' . $token,
						'relative_file' => '2026/04/other-author-' . $token . '.pdf',
					)
				);
				$state['otherAuthorIds'][] = (int) $other_author->ID;

				$state['contentBefore'] = self::media_url_insert_content_counts();

				$wpdb = $GLOBALS['wpdb'] ?? null;
				if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
					$month_rows = $wpdb->get_results(
						"SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
						FROM {$wpdb->posts}
						WHERE post_type = 'attachment'
						ORDER BY post_date DESC"
					);
					foreach ( $month_rows as $row ) {
						$state['monthRows'][] = array(
							'year'  => (int) ( $row->year ?? 0 ),
							'month' => (int) ( $row->month ?? 0 ),
						);
					}
				}

				$state['filenameFilterBefore'] = \has_filter( 'wp_allow_query_attachment_by_filename' );
				$state['queries']['filenameDate'] = self::media_library_query_date_capture_query(
					array(
						'post_mime_type' => 'image',
						'm'              => '202606',
						's'              => $state['filenameTerm'],
						'paged'          => 2,
					)
				);
				$state['filenameFilterAfter'] = \has_filter( 'wp_allow_query_attachment_by_filename' );

				$state['queries']['detached'] = self::media_library_query_date_capture_query(
					array(
						'attachment-filter' => 'detached',
						'post_mime_type'    => 'image',
						'paged'             => 1,
					)
				);

				$state['queries']['mine'] = self::media_library_query_date_capture_query(
					array(
						'attachment-filter' => 'mine',
						'paged'             => 1,
					)
				);

				$GLOBALS['pagenow']      = 'media-upload.php';
				$GLOBALS['type']         = 'image';
				$GLOBALS['tab']          = 'library';
				$GLOBALS['body_id']      = 'component-fuzz-query-date';
				$GLOBALS['wp']           = new \WP();
				$GLOBALS['wp_query']     = new \WP_Query();
				$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

				$_SERVER['HTTP_HOST']       = 'example.test';
				$_SERVER['HTTPS']           = 'off';
				$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
				$_SERVER['REQUEST_METHOD']  = 'GET';
				$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?type=image&tab=library&m=202606&post_mime_type=image';
				$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=image&tab=library';
				$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-query-date';
				$_SERVER['REMOTE_ADDR']     = '198.51.100.51';
				$_SERVER['SERVER_PORT']     = '80';

				$_GET     = array(
					'type'           => 'image',
					'tab'            => 'library',
					'post_id'        => (string) $parent_id,
					'post_mime_type' => 'image',
					'm'              => '202606',
				);
				$_POST    = array();
				$_REQUEST = $_GET;
				$_FILES   = array();
				$_COOKIE  = array();

				$return_value = \media_upload_library();
				$state['returnType'] = gettype( $return_value );
				$state['returned']   = true;

				$render_query = $GLOBALS['wp_the_query'] ?? null;
				$render_posts = is_object( $render_query ) && is_array( $render_query->posts ?? null ) ? $render_query->posts : array();
				$state['queries']['renderLibrary'] = array(
					'ids'         => array_map(
						static function ( $post ): int {
							return (int) ( $post->ID ?? 0 );
						},
						$render_posts
					),
					'queryVars'   => is_object( $render_query ) && is_array( $render_query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $render_query->query_vars ) : array(),
					'foundPosts'  => is_object( $render_query ) && isset( $render_query->found_posts ) ? (int) $render_query->found_posts : null,
					'maxNumPages' => is_object( $render_query ) && isset( $render_query->max_num_pages ) ? (int) $render_query->max_num_pages : null,
				);
			} finally {
				\remove_filter( 'upload_per_page', $upload_per_page_filter, 10 );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_library_query_date_capture_query( array $args ): array {
		$GLOBALS['wp']           = new \WP();
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		\wp_edit_attachments_query( $args );

		$query = $GLOBALS['wp_the_query'] ?? null;
		$posts = is_object( $query ) && is_array( $query->posts ?? null ) ? $query->posts : array();

		return array(
			'ids'         => array_map(
				static function ( $post ): int {
					return (int) ( $post->ID ?? 0 );
				},
				$posts
			),
			'queryVars'   => is_object( $query ) && is_array( $query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $query->query_vars ) : array(),
			'foundPosts'  => is_object( $query ) && isset( $query->found_posts ) ? (int) $query->found_posts : null,
			'maxNumPages' => is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : null,
		);
	}

	private static function media_library_query_date_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['filenameMatchIds'] ?? null )
			&& is_array( $result['filenameExpectedPage2'] ?? null )
			&& is_array( $result['outsideMonthIds'] ?? null )
			&& is_array( $result['detachedIds'] ?? null )
			&& is_array( $result['attachedControlIds'] ?? null )
			&& is_array( $result['mineIds'] ?? null )
			&& is_array( $result['otherAuthorIds'] ?? null )
			&& is_int( $result['currentUserId'] ?? null )
			&& is_array( $result['monthRows'] ?? null )
			&& is_array( $result['queries'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null );
	}

	private static function collect_media_library_query_date_failures( array &$failures, array $case, array $result ): void {
		unset( $case );

		$output         = (string) ( $result['output'] ?? '' );
		$filename_query = is_array( $result['queries']['filenameDate'] ?? null ) ? $result['queries']['filenameDate'] : array();
		$detached_query = is_array( $result['queries']['detached'] ?? null ) ? $result['queries']['detached'] : array();
		$mine_query     = is_array( $result['queries']['mine'] ?? null ) ? $result['queries']['mine'] : array();
		$render_query   = is_array( $result['queries']['renderLibrary'] ?? null ) ? $result['queries']['renderLibrary'] : array();

		$month_keys = array_map(
			static function ( array $row ): string {
				return sprintf( '%04d%02d', (int) ( $row['year'] ?? 0 ), (int) ( $row['month'] ?? 0 ) );
			},
			$result['monthRows'] ?? array()
		);

		self::collect_failure(
			$failures,
			in_array( '202607', $month_keys, true )
				&& in_array( '202606', $month_keys, true )
				&& in_array( '202605', $month_keys, true )
				&& in_array( '202604', $month_keys, true )
				&& count( $month_keys ) === count( array_unique( $month_keys ) )
				&& str_contains( $output, "<select name='m'>" )
				&& str_contains( $output, "selected='selected' value='202606'" )
				&& str_contains( $output, '>June 2026</option>' ),
			'media library month dropdown receives distinct post_date year/month rows and selects the generated request month',
			array(
				'monthRows' => $result['monthRows'] ?? array(),
				'output'    => self::describe_string( $output ),
			)
		);

		$render_query_vars = is_array( $render_query['queryVars'] ?? null ) ? $render_query['queryVars'] : array();
		self::collect_failure(
			$failures,
			7 === (int) ( $render_query['foundPosts'] ?? 0 )
				&& 2 === (int) ( $render_query['maxNumPages'] ?? 0 )
				&& 5 === (int) ( $render_query_vars['posts_per_page'] ?? 0 )
				&& 202606 === (int) ( $render_query_vars['m'] ?? 0 )
				&& ! str_contains( $output, 'tablenav-pages' ),
			'legacy library form exposes the upload_per_page versus hard-coded 10 pagination boundary for generated month results',
			array(
				'query'  => $render_query,
				'output' => self::describe_string( $output ),
			)
		);

		$filename_ids          = array_values( array_map( 'intval', $filename_query['ids'] ?? array() ) );
		$filename_expected_ids = array_values( array_map( 'intval', $result['filenameExpectedPage2'] ?? array() ) );
		$filename_query_vars   = is_array( $filename_query['queryVars'] ?? null ) ? $filename_query['queryVars'] : array();

		self::collect_failure(
			$failures,
			$filename_expected_ids === $filename_ids
				&& 7 === (int) ( $filename_query['foundPosts'] ?? 0 )
				&& 2 === (int) ( $filename_query['maxNumPages'] ?? 0 )
				&& 5 === (int) ( $filename_query_vars['posts_per_page'] ?? 0 )
				&& 2 === (int) ( $filename_query_vars['paged'] ?? 0 )
				&& 202606 === (int) ( $filename_query_vars['m'] ?? 0 )
				&& 'image' === (string) ( $filename_query_vars['post_mime_type'] ?? '' )
				&& false === ( $result['filenameFilterAfter'] ?? null )
				&& array() === array_intersect( $filename_ids, array_map( 'intval', $result['outsideMonthIds'] ?? array() ) ),
			'filename search opt-in reaches _wp_attached_file matches, honors m=YYYYMM filtering and pagination, then cleans the filename query filter',
			array(
				'expectedIds'           => $filename_expected_ids,
				'query'                 => $filename_query,
				'filenameMatchIds'      => $result['filenameMatchIds'] ?? array(),
				'outsideMonthIds'       => $result['outsideMonthIds'] ?? array(),
				'filenameFilterBefore'  => $result['filenameFilterBefore'] ?? null,
				'filenameFilterAfter'   => $result['filenameFilterAfter'] ?? null,
			)
		);

		$detached_ids        = array_values( array_map( 'intval', $detached_query['ids'] ?? array() ) );
		$detached_query_vars = is_array( $detached_query['queryVars'] ?? null ) ? $detached_query['queryVars'] : array();
		self::collect_failure(
			$failures,
			self::same_int_set( $detached_ids, array_map( 'intval', $result['detachedIds'] ?? array() ) )
				&& 0 === (int) ( $detached_query_vars['post_parent'] ?? -1 )
				&& 'image' === (string) ( $detached_query_vars['post_mime_type'] ?? '' )
				&& ! array_intersect( $detached_ids, array_map( 'intval', $result['attachedControlIds'] ?? array() ) ),
			'detached media filter constrains the attachment query to parent-zero image rows',
			array(
				'query'              => $detached_query,
				'detachedIds'        => $result['detachedIds'] ?? array(),
				'attachedControlIds' => $result['attachedControlIds'] ?? array(),
			)
		);

		$mine_ids        = array_values( array_map( 'intval', $mine_query['ids'] ?? array() ) );
		$mine_query_vars = is_array( $mine_query['queryVars'] ?? null ) ? $mine_query['queryVars'] : array();
		self::collect_failure(
			$failures,
			self::same_int_set( $mine_ids, array_map( 'intval', $result['mineIds'] ?? array() ) )
				&& (int) ( $result['currentUserId'] ?? 0 ) === (int) ( $mine_query_vars['author'] ?? 0 )
				&& ! array_intersect( $mine_ids, array_map( 'intval', $result['otherAuthorIds'] ?? array() ) ),
			'mine media filter constrains the attachment query to the current user without leaking other authors',
			array(
				'query'          => $mine_query,
				'mineIds'        => $result['mineIds'] ?? array(),
				'otherAuthorIds' => $result['otherAuthorIds'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() ),
			'query/date filter probes and library form rendering do not mutate content rows',
			array(
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
			)
		);
	}

	private static function check_media_library_query_date_stub_edges( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-library-date-stub-edges',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$case     = self::media_library_query_date_stub_edge_case( $ctx );
		$run      = self::run_media_library_query_date_stub_edge_child_process( $case );
		$result   = is_array( $run['result'] ?? null ) ? $run['result'] : array();
		$failures = array();

		self::collect_failure(
			$failures,
			true === ( $run['ok'] ?? false ) && self::media_library_query_date_stub_edge_child_result_has_expected_shape( $result ),
			'media query date/stub edge child reports structured JSON',
			array(
				'run'    => $run,
				'result' => $result,
			)
		);

		if ( self::media_library_query_date_stub_edge_child_result_has_expected_shape( $result ) ) {
			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& 'NULL' === (string) ( $result['returnType'] ?? '' )
					&& null === ( $result['throwable'] ?? null ),
				'media query date/stub edge child reaches list-table and query probes without exceptions',
				array(
					'returned'  => $result['returned'] ?? null,
					'returnType' => $result['returnType'] ?? null,
					'throwable' => $result['throwable'] ?? null,
				)
			);

			self::collect_media_library_query_date_stub_edge_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-library-date-stub-edges',
			array() === $failures,
			array(
				'failures' => $failures,
				'run'      => array(
					'ok'       => $run['ok'] ?? false,
					'exitCode' => $run['exitCode'] ?? null,
					'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
					'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
					'result'   => array(
						'returned'              => $result['returned'] ?? null,
						'returnType'            => $result['returnType'] ?? null,
						'throwable'             => $result['throwable'] ?? null,
						'monthRows'             => $result['monthRows'] ?? array(),
						'listTable'             => $result['listTable'] ?? array(),
						'queries'               => $result['queries'] ?? array(),
						'exactTimestampIds'     => $result['exactTimestampIds'] ?? array(),
						'dateQueryWindowIds'    => $result['dateQueryWindowIds'] ?? array(),
						'dateQueryControlIds'   => $result['dateQueryControlIds'] ?? array(),
						'excludedMonthStatuses' => $result['excludedMonthStatuses'] ?? array(),
						'contentBefore'         => $result['contentBefore'] ?? array(),
						'contentAfter'          => $result['contentAfter'] ?? array(),
						'output'                => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					),
				),
			)
		);
	}

	private static function media_library_query_date_stub_edge_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'label'     => 'month-status-exclusions-date-units',
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'token'     => self::media_upload_dispatch_token( 'mqdse_' . $ctx->identifier( 4, 9 ) ),
		);
	}

	private static function run_media_library_query_date_stub_edge_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates media date-query/list-table globals in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_library_query_date_stub_edge_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_library_query_date_stub_edge_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_library_query_date_stub_edge_child( $case );
PHP;
	}

	public static function run_media_library_query_date_stub_edge_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx   = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$token = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state = array(
			'ok'                    => false,
			'label'                 => (string) ( $case['label'] ?? 'media-library-query-date-stub-edges' ),
			'token'                 => $token,
			'parentId'              => 0,
			'exactTimestampIds'     => array(),
			'dateQueryWindowIds'    => array(),
			'dateQueryControlIds'   => array(),
			'excludedMonthStatuses' => array(
				'autoDraftIds' => array(),
				'trashIds'     => array(),
			),
			'monthRows'             => array(),
			'listTable'             => array(),
			'queries'               => array(),
			'contentBefore'         => array(),
			'contentAfter'          => array(),
			'returned'              => false,
			'returnType'            => null,
			'throwable'             => null,
			'output'                => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			if ( defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-media-list-table.php';
			}

			$parent_id = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$state['parentId'] = $parent_id;

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$upload_per_page_filter = static function (): int {
				return 50;
			};

			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'upload_per_page', $upload_per_page_filter, 10, 0 );

			try {
				$exact = self::seed_attachment(
					$ctx->fork( 'exact-timestamp' ),
					'image/jpeg',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-12-14 06:07:08',
						'post_date_gmt' => '2026-12-14 06:07:08',
						'post_title'    => 'Exact timestamp ' . $token,
						'relative_file' => '2026/12/exact-timestamp-' . $token . '.jpg',
					)
				);
				$state['exactTimestampIds'][]  = (int) $exact->ID;
				$state['dateQueryWindowIds'][] = (int) $exact->ID;

				$hour_window = self::seed_attachment(
					$ctx->fork( 'hour-window' ),
					'image/jpeg',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-12-14 07:07:08',
						'post_date_gmt' => '2026-12-14 07:07:08',
						'post_title'    => 'Hour window ' . $token,
						'relative_file' => '2026/12/hour-window-' . $token . '.jpg',
					)
				);
				$state['dateQueryWindowIds'][] = (int) $hour_window->ID;

				foreach (
					array(
						'wrong-hour'   => '2026-12-14 08:07:08',
						'wrong-minute' => '2026-12-14 06:08:08',
						'wrong-second' => '2026-12-14 06:07:09',
						'wrong-day'    => '2026-12-15 06:07:08',
						'wrong-month'  => '2026-11-14 06:07:08',
					) as $label => $date
				) {
					$control = self::seed_attachment(
						$ctx->fork( $label ),
						'image/jpeg',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => 2,
							'post_date'     => $date,
							'post_date_gmt' => $date,
							'post_title'    => $label . ' ' . $token,
							'relative_file' => '2026/12/' . $label . '-' . $token . '.jpg',
						)
					);
					$state['dateQueryControlIds'][] = (int) $control->ID;
				}

				$auto_draft = self::seed_attachment(
					$ctx->fork( 'auto-draft-month' ),
					'image/jpeg',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_status'   => 'auto-draft',
						'post_date'     => '2027-01-05 10:00:00',
						'post_date_gmt' => '2027-01-05 10:00:00',
						'post_title'    => 'Auto draft excluded month ' . $token,
						'relative_file' => '2027/01/auto-draft-month-' . $token . '.jpg',
					)
				);
				$state['excludedMonthStatuses']['autoDraftIds'][] = (int) $auto_draft->ID;

				$trash = self::seed_attachment(
					$ctx->fork( 'trash-month' ),
					'image/jpeg',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_status'   => 'trash',
						'post_date'     => '2027-02-05 10:00:00',
						'post_date_gmt' => '2027-02-05 10:00:00',
						'post_title'    => 'Trash excluded month ' . $token,
						'relative_file' => '2027/02/trash-month-' . $token . '.jpg',
					)
				);
				$state['excludedMonthStatuses']['trashIds'][] = (int) $trash->ID;

				$state['contentBefore'] = self::media_url_insert_content_counts();

				$wpdb = $GLOBALS['wpdb'] ?? null;
				if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) ) {
					$month_rows = $wpdb->get_results(
						"SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
						FROM {$wpdb->posts}
						WHERE post_type = 'attachment'
						AND post_status != 'auto-draft'
						AND post_status != 'trash'
						ORDER BY post_date DESC"
					);
					foreach ( $month_rows as $row ) {
						$state['monthRows'][] = array(
							'year'  => (int) ( $row->year ?? 0 ),
							'month' => (int) ( $row->month ?? 0 ),
						);
					}
				}

				$state['queries']['fullTimestampM'] = self::media_library_query_date_capture_query(
					array(
						'post_mime_type' => 'image',
						'm'              => '20261214060708',
					)
				);
				$state['queries']['dateQueryIn'] = self::media_library_date_stub_edge_capture_wp_query(
					array(
						'post_mime_type' => 'image',
						'date_query'     => array(
							array(
								'compare' => 'IN',
								'year'    => array( 2026 ),
								'month'   => array( 12 ),
								'day'     => array( 14 ),
								'hour'    => array( 6, 7 ),
								'minute'  => array( 7 ),
								'second'  => array( 8 ),
							),
						),
					)
				);
				$state['queries']['dateQueryBetween'] = self::media_library_date_stub_edge_capture_wp_query(
					array(
						'post_mime_type' => 'image',
						'date_query'     => array(
							array(
								'compare' => 'BETWEEN',
								'year'    => array( 2026, 2026 ),
								'month'   => array( 12, 12 ),
								'day'     => array( 14, 14 ),
								'hour'    => array( 6, 7 ),
								'minute'  => array( 7, 7 ),
								'second'  => array( 8, 8 ),
							),
						),
					)
				);

				$state['listTable'] = self::media_library_date_stub_edge_list_table_probe();
				$state['returnType'] = 'NULL';
				$state['returned']   = true;
			} finally {
				\remove_filter( 'upload_per_page', $upload_per_page_filter, 10 );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_library_date_stub_edge_capture_wp_query( array $args ): array {
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'           => 'attachment',
					'post_status'         => array( 'inherit', 'private' ),
					'posts_per_page'      => 50,
					'ignore_sticky_posts' => true,
				),
				$args
			)
		);

		$posts = is_array( $query->posts ?? null ) ? $query->posts : array();

		return array(
			'ids'         => array_map(
				static function ( $post ): int {
					return (int) ( $post->ID ?? 0 );
				},
				$posts
			),
			'queryVars'   => is_array( $query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $query->query_vars ) : array(),
			'foundPosts'  => isset( $query->found_posts ) ? (int) $query->found_posts : null,
			'maxNumPages' => isset( $query->max_num_pages ) ? (int) $query->max_num_pages : null,
		);
	}

	private static function media_library_date_stub_edge_list_table_probe(): array {
		$GLOBALS['pagenow']      = 'upload.php';
		$GLOBALS['wp']           = new \WP();
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['body_id']      = 'component-fuzz-date-stub-list-table';

		$_SERVER['HTTP_HOST']       = 'example.test';
		$_SERVER['HTTPS']           = 'off';
		$_SERVER['PHP_SELF']        = '/wp-admin/upload.php';
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REQUEST_URI']     = '/wp-admin/upload.php?post_mime_type=image';
		$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/upload.php';
		$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-date-stub-edge';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.53';
		$_SERVER['SERVER_PORT']     = '80';

		$_GET     = array( 'post_mime_type' => 'image' );
		$_POST    = array();
		$_REQUEST = $_GET;
		$_FILES   = array();
		$_COOKIE  = array();

		$screen = \convert_to_screen( 'upload' );
		$table  = \_get_list_table( 'WP_Media_List_Table', array( 'screen' => $screen ) );
		if ( ! $table instanceof \WP_List_Table ) {
			throw new \RuntimeException( 'Could not load WP_Media_List_Table for date stub edge probe.' );
		}

		$table->prepare_items();
		ob_start();
		self::invoke_object_method( $table, 'extra_tablenav', array( 'bar' ) );
		$month_output = ob_get_clean();

		$query = $GLOBALS['wp_the_query'] ?? null;
		$posts = is_object( $query ) && is_array( $query->posts ?? null ) ? $query->posts : array();

		return array(
			'ids'         => array_map(
				static function ( $post ): int {
					return (int) ( $post->ID ?? 0 );
				},
				$posts
			),
			'queryVars'   => is_object( $query ) && is_array( $query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $query->query_vars ) : array(),
			'foundPosts'  => is_object( $query ) && isset( $query->found_posts ) ? (int) $query->found_posts : null,
			'maxNumPages' => is_object( $query ) && isset( $query->max_num_pages ) ? (int) $query->max_num_pages : null,
			'monthOutput' => is_string( $month_output ) ? $month_output : '',
		);
	}

	private static function media_library_query_date_stub_edge_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_array( $result['exactTimestampIds'] ?? null )
			&& is_array( $result['dateQueryWindowIds'] ?? null )
			&& is_array( $result['dateQueryControlIds'] ?? null )
			&& is_array( $result['excludedMonthStatuses'] ?? null )
			&& is_array( $result['monthRows'] ?? null )
			&& is_array( $result['listTable'] ?? null )
			&& is_array( $result['queries'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null );
	}

	private static function collect_media_library_query_date_stub_edge_failures( array &$failures, array $case, array $result ): void {
		unset( $case );

		$month_keys = array_map(
			static function ( array $row ): string {
				return sprintf( '%04d%02d', (int) ( $row['year'] ?? 0 ), (int) ( $row['month'] ?? 0 ) );
			},
			$result['monthRows'] ?? array()
		);
		$list_table       = is_array( $result['listTable'] ?? null ) ? $result['listTable'] : array();
		$list_month_html  = (string) ( $list_table['monthOutput'] ?? '' );
		$excluded_status  = is_array( $result['excludedMonthStatuses'] ?? null ) ? $result['excludedMonthStatuses'] : array();

		self::collect_failure(
			$failures,
			in_array( '202612', $month_keys, true )
				&& ! in_array( '202701', $month_keys, true )
				&& ! in_array( '202702', $month_keys, true )
				&& str_contains( $list_month_html, "value='202612'" )
				&& ! str_contains( $list_month_html, "value='202701'" )
				&& ! str_contains( $list_month_html, "value='202702'" ),
			'media list-table month dropdown SQL applies every post_status != predicate before projecting distinct months',
			array(
				'monthRows'             => $result['monthRows'] ?? array(),
				'listTable'             => array(
					'queryVars'   => $list_table['queryVars'] ?? array(),
					'foundPosts'  => $list_table['foundPosts'] ?? null,
					'monthOutput' => self::describe_string( $list_month_html ),
				),
				'excludedMonthStatuses' => $excluded_status,
			)
		);

		$full_timestamp_query = is_array( $result['queries']['fullTimestampM'] ?? null ) ? $result['queries']['fullTimestampM'] : array();
		$full_timestamp_ids   = array_values( array_map( 'intval', $full_timestamp_query['ids'] ?? array() ) );
		$exact_ids            = array_values( array_map( 'intval', $result['exactTimestampIds'] ?? array() ) );

		self::collect_failure(
			$failures,
			self::same_int_set( $full_timestamp_ids, $exact_ids )
				&& '20261214060708' === (string) ( $full_timestamp_query['queryVars']['m'] ?? '' ),
			'full m=YYYYMMDDHHIISS media queries constrain year/month/day/hour/minute/second together',
			array(
				'query'    => $full_timestamp_query,
				'expected' => $exact_ids,
				'controls' => $result['dateQueryControlIds'] ?? array(),
			)
		);

		$in_query      = is_array( $result['queries']['dateQueryIn'] ?? null ) ? $result['queries']['dateQueryIn'] : array();
		$between_query = is_array( $result['queries']['dateQueryBetween'] ?? null ) ? $result['queries']['dateQueryBetween'] : array();
		$window_ids    = array_values( array_map( 'intval', $result['dateQueryWindowIds'] ?? array() ) );
		$control_ids   = array_values( array_map( 'intval', $result['dateQueryControlIds'] ?? array() ) );
		$in_ids        = array_values( array_map( 'intval', $in_query['ids'] ?? array() ) );
		$between_ids   = array_values( array_map( 'intval', $between_query['ids'] ?? array() ) );

		self::collect_failure(
			$failures,
			self::same_int_set( $in_ids, $window_ids )
				&& array() === array_intersect( $in_ids, $control_ids ),
			'WP_Date_Query IN projections filter generated year/month/day/hour/minute/second media rows',
			array(
				'query'    => $in_query,
				'expected' => $window_ids,
				'controls' => $control_ids,
			)
		);

		self::collect_failure(
			$failures,
			self::same_int_set( $between_ids, $window_ids )
				&& array() === array_intersect( $between_ids, $control_ids ),
			'WP_Date_Query BETWEEN projections filter generated year/month/day/hour/minute/second media rows',
			array(
				'query'    => $between_query,
				'expected' => $window_ids,
				'controls' => $control_ids,
			)
		);

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() ),
			'date stub edge probes do not mutate content rows',
			array(
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
			)
		);
	}

	private static function same_int_set( array $actual, array $expected ): bool {
		$actual   = array_values( array_unique( array_map( 'intval', $actual ) ) );
		$expected = array_values( array_unique( array_map( 'intval', $expected ) ) );
		sort( $actual );
		sort( $expected );

		return $actual === $expected;
	}

	private static function check_media_library_query_alias_defaults( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.legacy-library-query-alias-defaults',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$case   = self::media_library_query_alias_default_case( $ctx );
		$run    = self::run_media_library_query_alias_default_child_process( $case );
		$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();
		$failures = array();

		self::collect_failure(
			$failures,
			true === ( $run['ok'] ?? false ) && self::media_library_query_alias_default_child_result_has_expected_shape( $result ),
			'media query alias/default child reports structured JSON',
			array(
				'run'    => $run,
				'result' => $result,
			)
		);

		if ( self::media_library_query_alias_default_child_result_has_expected_shape( $result ) ) {
			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& 'NULL' === (string) ( $result['returnType'] ?? '' )
					&& null === ( $result['throwable'] ?? null ),
				'media query alias/default child renders the default-filtered library form without exceptions',
				array(
					'returned'  => $result['returned'] ?? null,
					'returnType' => $result['returnType'] ?? null,
					'throwable' => $result['throwable'] ?? null,
				)
			);

			self::collect_media_library_query_alias_default_failures( $failures, $case, $result );
		}

		return self::row(
			$ctx,
			'admin-media-chrome.legacy-library-query-alias-defaults',
			array() === $failures,
			array(
				'failures' => $failures,
				'run'      => array(
					'ok'       => $run['ok'] ?? false,
					'exitCode' => $run['exitCode'] ?? null,
					'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
					'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
					'result'   => array(
						'returned'       => $result['returned'] ?? null,
						'returnType'     => $result['returnType'] ?? null,
						'throwable'      => $result['throwable'] ?? null,
						'defaultQuery'   => $result['defaultQuery'] ?? array(),
						'trashQueries'   => $result['trashQueries'] ?? array(),
						'aliasQueries'   => $result['aliasQueries'] ?? array(),
						'listTable'      => $result['listTable'] ?? array(),
						'contentBefore'  => $result['contentBefore'] ?? array(),
						'contentAfter'   => $result['contentAfter'] ?? array(),
						'output'         => self::describe_string( (string) ( $result['output'] ?? '' ) ),
					),
				),
			)
		);
	}

	private static function media_library_query_alias_default_case( \ComponentFuzz\FuzzContext $ctx ): array {
		return array(
			'label'     => 'default-trash-alias-list-table',
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'token'     => self::media_upload_dispatch_token( 'mqal_' . $ctx->identifier( 4, 9 ) ),
		);
	}

	private static function run_media_library_query_alias_default_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates media library query alias/default globals and list-table state in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_library_query_alias_default_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_library_query_alias_default_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_library_query_alias_default_child( $case );
PHP;
	}

	public static function run_media_library_query_alias_default_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx   = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$token = self::media_upload_dispatch_token( (string) ( $case['token'] ?? $ctx->identifier( 4, 9 ) ) );
		$state = array(
			'ok'                    => false,
			'label'                 => (string) ( $case['label'] ?? 'media-library-query-alias-default' ),
			'token'                 => $token,
			'currentUserId'         => 0,
			'parentId'              => 0,
			'defaultImageIds'       => array(),
			'defaultNonImageIds'    => array(),
			'normalIds'             => array(),
			'trashIds'              => array(),
			'detachedIds'           => array(),
			'attachedControlIds'    => array(),
			'mineIds'               => array(),
			'otherAuthorIds'        => array(),
			'defaultQuery'          => array(),
			'trashQueries'          => array(),
			'aliasQueries'          => array(),
			'listTable'             => array(),
			'contentBefore'         => array(),
			'contentAfter'          => array(),
			'returned'              => false,
			'returnType'            => null,
			'throwable'             => null,
			'output'                => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['output']       = $output;
				$state['contentAfter'] = self::media_url_insert_content_counts();
				$state['ok']           = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			if ( defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-media-list-table.php';
			}

			$parent_id = self::seed_parent_post( $ctx->fork( 'parent' ) );
			$state['parentId'] = $parent_id;

			$current_user_id = \wp_insert_user(
				array(
					'user_login' => 'media-query-alias-' . substr( hash( 'crc32b', $token ), 0, 8 ),
					'user_pass'  => 'component-fuzz-password',
					'user_email' => 'media-query-alias-' . substr( hash( 'crc32b', $token ), 0, 8 ) . '@example.test',
					'role'       => 'administrator',
				)
			);
			if ( ! is_int( $current_user_id ) || $current_user_id <= 0 ) {
				throw new \RuntimeException( 'Could not seed current user for media library alias/default filters.' );
			}

			$state['currentUserId'] = $current_user_id;
			\wp_set_current_user( $current_user_id );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			$user_has_cap_filter = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$upload_per_page_filter = static function (): int {
				return 50;
			};

			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'upload_per_page', $upload_per_page_filter, 10, 0 );

			try {
				for ( $i = 0; $i < 3; ++$i ) {
					$attachment = self::seed_attachment(
						$ctx->fork( 'default-image-' . $i ),
						'image/jpeg',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => 2,
							'post_date'     => sprintf( '2026-08-%02d 10:00:00', 20 - $i ),
							'post_date_gmt' => sprintf( '2026-08-%02d 10:00:00', 20 - $i ),
							'post_title'    => 'Default image ' . $i . ' ' . $token,
							'relative_file' => '2026/08/default-image-' . $token . '-' . $i . '.jpg',
						)
					);
					$state['defaultImageIds'][] = (int) $attachment->ID;
					$state['normalIds'][]       = (int) $attachment->ID;
				}

				for ( $i = 0; $i < 2; ++$i ) {
					$attachment = self::seed_attachment(
						$ctx->fork( 'default-pdf-' . $i ),
						'application/pdf',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => 2,
							'post_date'     => sprintf( '2026-08-%02d 09:00:00', 10 - $i ),
							'post_date_gmt' => sprintf( '2026-08-%02d 09:00:00', 10 - $i ),
							'post_title'    => 'Default PDF ' . $i . ' ' . $token,
							'relative_file' => '2026/08/default-pdf-' . $token . '-' . $i . '.pdf',
						)
					);
					$state['defaultNonImageIds'][] = (int) $attachment->ID;
					$state['normalIds'][]          = (int) $attachment->ID;
				}

				for ( $i = 0; $i < 2; ++$i ) {
					$attachment = self::seed_attachment(
						$ctx->fork( 'trash-' . $i ),
						'image/png',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => 2,
							'post_status'   => 'trash',
							'post_date'     => sprintf( '2026-09-%02d 09:00:00', 10 - $i ),
							'post_date_gmt' => sprintf( '2026-09-%02d 09:00:00', 10 - $i ),
							'post_title'    => 'Trash image ' . $i . ' ' . $token,
							'relative_file' => '2026/09/trash-' . $token . '-' . $i . '.png',
						)
					);
					$state['trashIds'][] = (int) $attachment->ID;
				}

				for ( $i = 0; $i < 2; ++$i ) {
					$attachment = self::seed_attachment(
						$ctx->fork( 'detached-' . $i ),
						'image/png',
						array(
							'parent_id'     => 0,
							'post_author'   => 2,
							'post_date'     => sprintf( '2026-10-%02d 09:00:00', 10 - $i ),
							'post_date_gmt' => sprintf( '2026-10-%02d 09:00:00', 10 - $i ),
							'post_title'    => 'Detached alias ' . $i . ' ' . $token,
							'relative_file' => '2026/10/detached-alias-' . $token . '-' . $i . '.png',
						)
					);
					$state['detachedIds'][]     = (int) $attachment->ID;
					$state['defaultImageIds'][] = (int) $attachment->ID;
					$state['normalIds'][]       = (int) $attachment->ID;
				}

				$attached_control = self::seed_attachment(
					$ctx->fork( 'attached-control' ),
					'image/png',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-10-11 09:00:00',
						'post_date_gmt' => '2026-10-11 09:00:00',
						'post_title'    => 'Attached alias control ' . $token,
						'relative_file' => '2026/10/attached-control-' . $token . '.png',
					)
				);
				$state['attachedControlIds'][] = (int) $attached_control->ID;
				$state['defaultImageIds'][]    = (int) $attached_control->ID;
				$state['normalIds'][]          = (int) $attached_control->ID;

				for ( $i = 0; $i < 2; ++$i ) {
					$attachment = self::seed_attachment(
						$ctx->fork( 'mine-' . $i ),
						'application/pdf',
						array(
							'parent_id'     => $parent_id,
							'post_author'   => $current_user_id,
							'post_date'     => sprintf( '2026-11-%02d 08:00:00', 10 - $i ),
							'post_date_gmt' => sprintf( '2026-11-%02d 08:00:00', 10 - $i ),
							'post_title'    => 'Mine alias PDF ' . $i . ' ' . $token,
							'relative_file' => '2026/11/mine-alias-' . $token . '-' . $i . '.pdf',
						)
					);
					$state['mineIds'][]       = (int) $attachment->ID;
					$state['normalIds'][]     = (int) $attachment->ID;
					$state['defaultNonImageIds'][] = (int) $attachment->ID;
				}

				$other_author = self::seed_attachment(
					$ctx->fork( 'other-author' ),
					'application/pdf',
					array(
						'parent_id'     => $parent_id,
						'post_author'   => 2,
						'post_date'     => '2026-11-11 08:00:00',
						'post_date_gmt' => '2026-11-11 08:00:00',
						'post_title'    => 'Other alias author PDF ' . $token,
						'relative_file' => '2026/11/other-alias-author-' . $token . '.pdf',
					)
				);
				$state['otherAuthorIds'][]    = (int) $other_author->ID;
				$state['normalIds'][]         = (int) $other_author->ID;
				$state['defaultNonImageIds'][] = (int) $other_author->ID;

				$state['contentBefore'] = self::media_url_insert_content_counts();

				$state['trashQueries']['statusOnly'] = self::media_library_query_date_capture_query(
					array( 'status' => 'trash' )
				);
				$state['trashQueries']['attachmentFilter'] = self::media_library_query_date_capture_query(
					array( 'attachment-filter' => 'trash' )
				);
				$state['aliasQueries']['rawDetached'] = self::media_library_query_date_capture_query(
					array(
						'detached'       => '1',
						'post_mime_type' => 'image',
					)
				);
				$state['aliasQueries']['filterDetached'] = self::media_library_query_date_capture_query(
					array(
						'attachment-filter' => 'detached',
						'post_mime_type'    => 'image',
					)
				);
				$state['aliasQueries']['rawMine'] = self::media_library_query_date_capture_query(
					array( 'mine' => '1' )
				);
				$state['aliasQueries']['filterMine'] = self::media_library_query_date_capture_query(
					array( 'attachment-filter' => 'mine' )
				);

				$GLOBALS['pagenow']      = 'media-upload.php';
				$GLOBALS['type']         = 'image';
				$GLOBALS['tab']          = 'library';
				$GLOBALS['body_id']      = 'component-fuzz-query-alias-default';
				$GLOBALS['wp']           = new \WP();
				$GLOBALS['wp_query']     = new \WP_Query();
				$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

				$_SERVER['HTTP_HOST']       = 'example.test';
				$_SERVER['HTTPS']           = 'off';
				$_SERVER['PHP_SELF']        = '/wp-admin/media-upload.php';
				$_SERVER['REQUEST_METHOD']  = 'GET';
				$_SERVER['REQUEST_URI']     = '/wp-admin/media-upload.php?type=image&tab=library';
				$_SERVER['HTTP_REFERER']    = 'http://example.test/wp-admin/media-upload.php?type=image&tab=library';
				$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-query-alias-default';
				$_SERVER['REMOTE_ADDR']     = '198.51.100.52';
				$_SERVER['SERVER_PORT']     = '80';

				$_GET     = array(
					'type'    => 'image',
					'tab'     => 'library',
					'post_id' => (string) $parent_id,
				);
				$_POST    = array();
				$_REQUEST = $_GET;
				$_FILES   = array();
				$_COOKIE  = array();

				$return_value = \media_upload_library();
				$state['returnType'] = gettype( $return_value );
				$state['returned']   = true;

				$default_query = $GLOBALS['wp_the_query'] ?? null;
				$default_posts = is_object( $default_query ) && is_array( $default_query->posts ?? null ) ? $default_query->posts : array();
				$state['defaultQuery'] = array(
					'ids'         => array_map(
						static function ( $post ): int {
							return (int) ( $post->ID ?? 0 );
						},
						$default_posts
					),
					'queryVars'   => is_object( $default_query ) && is_array( $default_query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $default_query->query_vars ) : array(),
					'foundPosts'  => is_object( $default_query ) && isset( $default_query->found_posts ) ? (int) $default_query->found_posts : null,
					'maxNumPages' => is_object( $default_query ) && isset( $default_query->max_num_pages ) ? (int) $default_query->max_num_pages : null,
					'getPostMimeTypeAfterRender' => $_GET['post_mime_type'] ?? null,
				);

				$screen = \convert_to_screen( 'upload' );
				$state['listTable']['rawDetached'] = self::media_library_alias_default_list_table_probe(
					array(
						'detached'       => '1',
						'post_mime_type' => 'image',
					),
					$screen
				);
				$state['listTable']['filterDetached'] = self::media_library_alias_default_list_table_probe(
					array(
						'attachment-filter' => 'detached',
						'post_mime_type'    => 'image',
					),
					$screen
				);
			} finally {
				\remove_filter( 'upload_per_page', $upload_per_page_filter, 10 );
				\remove_filter( 'user_has_cap', $user_has_cap_filter, 10 );
			}
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_library_alias_default_list_table_probe( array $request, \WP_Screen $screen ): array {
		$GLOBALS['pagenow']      = 'upload.php';
		$GLOBALS['wp']           = new \WP();
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$_GET                    = $request;
		$_POST                   = array();
		$_REQUEST                = $request;
		$_SERVER['PHP_SELF']     = '/wp-admin/upload.php';
		$_SERVER['REQUEST_URI']  = '/wp-admin/upload.php?' . http_build_query( $request, '', '&', PHP_QUERY_RFC3986 );

		$table = \_get_list_table( 'WP_Media_List_Table', array( 'screen' => $screen ) );
		if ( ! $table instanceof \WP_List_Table ) {
			throw new \RuntimeException( 'Could not load WP_Media_List_Table.' );
		}

		$table->prepare_items();
		$views = self::invoke_object_method( $table, 'get_views' );

		$query = $GLOBALS['wp_the_query'] ?? null;
		$posts = is_object( $query ) && is_array( $query->posts ?? null ) ? $query->posts : array();

		return array(
			'ids'              => array_map(
				static function ( $post ): int {
					return (int) ( $post->ID ?? 0 );
				},
				$posts
			),
			'queryVars'        => is_object( $query ) && is_array( $query->query_vars ?? null ) ? self::media_library_gallery_query_summary( $query->query_vars ) : array(),
			'foundPosts'       => is_object( $query ) && isset( $query->found_posts ) ? (int) $query->found_posts : null,
			'views'            => is_array( $views ) ? $views : array(),
			'detachedSelected' => is_array( $views ) && str_contains( (string) ( $views['detached'] ?? '' ), 'selected="selected"' ),
		);
	}

	private static function invoke_object_method( object $object, string $method, array $args = array() ) {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invokeArgs( $object, $args );
	}

	private static function media_library_query_alias_default_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_string( $result['output'] ?? null )
			&& is_int( $result['currentUserId'] ?? null )
			&& is_array( $result['defaultImageIds'] ?? null )
			&& is_array( $result['defaultNonImageIds'] ?? null )
			&& is_array( $result['normalIds'] ?? null )
			&& is_array( $result['trashIds'] ?? null )
			&& is_array( $result['detachedIds'] ?? null )
			&& is_array( $result['attachedControlIds'] ?? null )
			&& is_array( $result['mineIds'] ?? null )
			&& is_array( $result['otherAuthorIds'] ?? null )
			&& is_array( $result['defaultQuery'] ?? null )
			&& is_array( $result['trashQueries'] ?? null )
			&& is_array( $result['aliasQueries'] ?? null )
			&& is_array( $result['listTable'] ?? null )
			&& is_array( $result['contentBefore'] ?? null )
			&& is_array( $result['contentAfter'] ?? null );
	}

	private static function collect_media_library_query_alias_default_failures( array &$failures, array $case, array $result ): void {
		unset( $case );

		$output             = (string) ( $result['output'] ?? '' );
		$default_query      = is_array( $result['defaultQuery'] ?? null ) ? $result['defaultQuery'] : array();
		$default_query_vars = is_array( $default_query['queryVars'] ?? null ) ? $default_query['queryVars'] : array();
		$default_ids        = array_values( array_map( 'intval', $default_query['ids'] ?? array() ) );

		self::collect_failure(
			$failures,
			self::same_int_set( $default_ids, array_map( 'intval', $result['defaultImageIds'] ?? array() ) )
				&& 'image' === (string) ( $default_query_vars['post_mime_type'] ?? '' )
				&& 'image' === (string) ( $default_query['getPostMimeTypeAfterRender'] ?? '' )
				&& ! array_intersect( $default_ids, array_map( 'intval', $result['defaultNonImageIds'] ?? array() ) )
				&& str_contains( $output, 'name="post_mime_type" value=""' )
				&& str_contains( $output, 'post_mime_type=image' )
				&& str_contains( $output, 'class="current">Images' ),
			'legacy library form reruns the query through the default media type while preserving the initially empty hidden post_mime_type input',
			array(
				'defaultQuery'       => $default_query,
				'defaultImageIds'    => $result['defaultImageIds'] ?? array(),
				'defaultNonImageIds' => $result['defaultNonImageIds'] ?? array(),
				'output'             => self::describe_string( $output ),
			)
		);

		$status_query = is_array( $result['trashQueries']['statusOnly'] ?? null ) ? $result['trashQueries']['statusOnly'] : array();
		$filter_query = is_array( $result['trashQueries']['attachmentFilter'] ?? null ) ? $result['trashQueries']['attachmentFilter'] : array();
		$status_ids   = array_values( array_map( 'intval', $status_query['ids'] ?? array() ) );
		$filter_ids   = array_values( array_map( 'intval', $filter_query['ids'] ?? array() ) );
		$status_vars  = is_array( $status_query['queryVars'] ?? null ) ? $status_query['queryVars'] : array();
		$filter_vars  = is_array( $filter_query['queryVars'] ?? null ) ? $filter_query['queryVars'] : array();

		self::collect_failure(
			$failures,
			self::same_int_set( $status_ids, array_map( 'intval', $result['normalIds'] ?? array() ) )
				&& self::same_int_set( $filter_ids, array_map( 'intval', $result['trashIds'] ?? array() ) )
				&& 'trash' !== (string) ( $status_vars['post_status'] ?? '' )
				&& 'trash' === (string) ( $filter_vars['post_status'] ?? '' )
				&& ! array_intersect( $status_ids, array_map( 'intval', $result['trashIds'] ?? array() ) ),
			'status=trash alone is normalized back to non-trash media while attachment-filter=trash selects trash attachments',
			array(
				'statusOnly'       => $status_query,
				'attachmentFilter' => $filter_query,
				'normalIds'        => $result['normalIds'] ?? array(),
				'trashIds'         => $result['trashIds'] ?? array(),
			)
		);

		$raw_detached    = is_array( $result['aliasQueries']['rawDetached'] ?? null ) ? $result['aliasQueries']['rawDetached'] : array();
		$filter_detached = is_array( $result['aliasQueries']['filterDetached'] ?? null ) ? $result['aliasQueries']['filterDetached'] : array();
		$raw_mine        = is_array( $result['aliasQueries']['rawMine'] ?? null ) ? $result['aliasQueries']['rawMine'] : array();
		$filter_mine     = is_array( $result['aliasQueries']['filterMine'] ?? null ) ? $result['aliasQueries']['filterMine'] : array();
		$raw_detached_ids = array_values( array_map( 'intval', $raw_detached['ids'] ?? array() ) );
		$filter_detached_ids = array_values( array_map( 'intval', $filter_detached['ids'] ?? array() ) );
		$raw_mine_ids = array_values( array_map( 'intval', $raw_mine['ids'] ?? array() ) );
		$filter_mine_ids = array_values( array_map( 'intval', $filter_mine['ids'] ?? array() ) );

		self::collect_failure(
			$failures,
			self::same_int_set( $raw_detached_ids, array_map( 'intval', $result['detachedIds'] ?? array() ) )
				&& self::same_int_set( $filter_detached_ids, array_map( 'intval', $result['detachedIds'] ?? array() ) )
				&& ! array_intersect( $raw_detached_ids, array_map( 'intval', $result['attachedControlIds'] ?? array() ) )
				&& 0 === (int) ( $raw_detached['queryVars']['post_parent'] ?? -1 )
				&& 0 === (int) ( $filter_detached['queryVars']['post_parent'] ?? -1 ),
			'raw detached alias and attachment-filter=detached both constrain the media query to parent-zero rows',
			array(
				'rawDetached'     => $raw_detached,
				'filterDetached'  => $filter_detached,
				'detachedIds'     => $result['detachedIds'] ?? array(),
				'attachedControlIds' => $result['attachedControlIds'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			self::same_int_set( $raw_mine_ids, array_map( 'intval', $result['mineIds'] ?? array() ) )
				&& self::same_int_set( $filter_mine_ids, array_map( 'intval', $result['mineIds'] ?? array() ) )
				&& (int) ( $result['currentUserId'] ?? 0 ) === (int) ( $raw_mine['queryVars']['author'] ?? 0 )
				&& (int) ( $result['currentUserId'] ?? 0 ) === (int) ( $filter_mine['queryVars']['author'] ?? 0 )
				&& ! array_intersect( $raw_mine_ids, array_map( 'intval', $result['otherAuthorIds'] ?? array() ) ),
			'raw mine alias and attachment-filter=mine both constrain the media query to current-user rows',
			array(
				'rawMine'        => $raw_mine,
				'filterMine'     => $filter_mine,
				'mineIds'        => $result['mineIds'] ?? array(),
				'otherAuthorIds' => $result['otherAuthorIds'] ?? array(),
			)
		);

		$raw_table    = is_array( $result['listTable']['rawDetached'] ?? null ) ? $result['listTable']['rawDetached'] : array();
		$filter_table = is_array( $result['listTable']['filterDetached'] ?? null ) ? $result['listTable']['filterDetached'] : array();
		self::collect_failure(
			$failures,
			self::same_int_set( array_map( 'intval', $raw_table['ids'] ?? array() ), array_map( 'intval', $result['detachedIds'] ?? array() ) )
				&& self::same_int_set( array_map( 'intval', $filter_table['ids'] ?? array() ), array_map( 'intval', $result['detachedIds'] ?? array() ) )
				&& false === ( $raw_table['detachedSelected'] ?? null )
				&& true === ( $filter_table['detachedSelected'] ?? null ),
			'WP_Media_List_Table query honors raw detached but only attachment-filter=detached selects the Unattached UI view',
			array(
				'rawTable'     => $raw_table,
				'filterTable'  => $filter_table,
				'detachedIds'  => $result['detachedIds'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			( $result['contentBefore'] ?? array() ) === ( $result['contentAfter'] ?? array() ),
			'query alias/default probes and default media library rendering do not mutate content rows',
			array(
				'contentBefore' => $result['contentBefore'] ?? array(),
				'contentAfter'  => $result['contentAfter'] ?? array(),
			)
		);
	}

	private static function check_media_attach_action_redirect_exit( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::media_attach_action_child_missing_requirements();
		if ( array() !== $missing ) {
			return self::row(
				$ctx,
				'admin-media-chrome.media-attach-action-redirect-exit',
				true,
				array(
					'missing' => $missing,
					'reason'  => 'Required local subprocess APIs are unavailable.',
				),
				'skipped'
			);
		}

		$failures = array();
		$runs     = array();

		foreach ( self::media_attach_action_cases( $ctx ) as $case ) {
			$run    = self::run_media_attach_action_child_process( $case );
			$result = is_array( $run['result'] ?? null ) ? $run['result'] : array();

			$runs[ $case['label'] ] = array(
				'ok'       => $run['ok'] ?? false,
				'exitCode' => $run['exitCode'] ?? null,
				'stderr'   => self::describe_string( (string) ( $run['stderr'] ?? '' ) ),
				'stdout'   => self::describe_string( (string) ( $run['stdout'] ?? '' ) ),
				'result'   => array(
					'returned'         => $result['returned'] ?? null,
					'redirects'        => $result['redirects'] ?? array(),
					'dieCalls'         => $result['dieCalls'] ?? array(),
					'rowsAffected'     => $result['rowsAffected'] ?? null,
					'queryDeltaCount'  => is_array( $result['queryDelta'] ?? null ) ? count( $result['queryDelta'] ) : null,
					'preReportOutput'  => self::describe_string( (string) ( $result['preReportOutput'] ?? '' ) ),
					'actionEventCount' => is_array( $result['actionEvents'] ?? null ) ? count( $result['actionEvents'] ) : null,
					'cacheEventCount'  => is_array( $result['cacheEvents'] ?? null ) ? count( $result['cacheEvents'] ) : null,
				),
			);

			self::collect_failure(
				$failures,
				true === ( $run['ok'] ?? false ) && self::media_attach_action_child_result_has_expected_shape( $result ),
				"{$case['label']} child process exits cleanly and reports structured JSON",
				array(
					'run'    => $run,
					'result' => $result,
				)
			);

			if ( ! self::media_attach_action_child_result_has_expected_shape( $result ) ) {
				continue;
			}

			self::collect_failure(
				$failures,
				'' === (string) ( $result['preReportOutput'] ?? '' ) && null === ( $result['throwable'] ?? null ),
				"{$case['label']} child emits no pre-report output and throws no unexpected exception",
				array(
					'preReportOutput' => $result['preReportOutput'] ?? null,
					'throwable'       => $result['throwable'] ?? null,
				)
			);

			if ( 'update' === $case['scenario'] ) {
				self::collect_media_attach_action_update_failures( $failures, $case, $result );
			} else {
				self::collect_media_attach_action_noop_failures( $failures, $case, $result );
			}
		}

		return self::row(
			$ctx,
			'admin-media-chrome.media-attach-action-redirect-exit',
			array() === $failures,
			array(
				'failures' => $failures,
				'runs'     => $runs,
			)
		);
	}

	private static function media_attach_action_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$build = static function ( string $label, string $scenario, string $action, \ComponentFuzz\FuzzContext $case_ctx ): array {
			$marker = 'cfz_' . $label . '_' . $case_ctx->identifier( 4, 9 );
			$paged  = (string) $case_ctx->int( 1, 9 );

			return array(
				'label'          => $label,
				'scenario'       => $scenario,
				'action'         => $action,
				'seed'           => $case_ctx->seed(),
				'iteration'      => $case_ctx->iteration(),
				'referer'        => 'http://example.test/wp-admin/upload.php?mode=list&attached=stale-attached&detach=stale-detach&cfz_marker=' . rawurlencode( $marker ) . '&paged=' . $paged,
				'preservedQuery' => array(
					'mode'       => 'list',
					'cfz_marker' => $marker,
					'paged'      => $paged,
				),
			);
		};

		return array(
			$build( 'attach', 'update', 'attach', $ctx->fork( 'attach' ) ),
			$build( 'detach', 'update', 'detach', $ctx->fork( 'detach' ) ),
			$build( 'parent-zero', 'parent-zero', $ctx->bool() ? 'attach' : 'detach', $ctx->fork( 'parent-zero' ) ),
			$build( 'denied-parent', 'denied-parent', 'attach', $ctx->fork( 'denied-parent' ) ),
		);
	}

	private static function media_attach_action_child_missing_requirements(): array {
		$missing = array();

		foreach ( array( 'json_decode', 'json_encode', 'proc_close', 'proc_open', 'stream_get_contents' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_media_attach_action_child_process( array $case ): array {
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates wp_media_attach_action() redirect, die, and exit branches in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, '-r', self::media_attach_action_child_program() ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
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

	private static function media_attach_action_child_program(): string {
		return <<<'PHP'
$component_fuzz_admin_media_raw = stream_get_contents( STDIN );
$component_fuzz_admin_media_payload = json_decode( $component_fuzz_admin_media_raw, true );
$case = is_array( $component_fuzz_admin_media_payload['case'] ?? null ) ? $component_fuzz_admin_media_payload['case'] : array();

require_once getcwd() . '/tools/component-fuzz/lib/autoload.php';
\ComponentFuzz\WpBootstrap::load();

\ComponentFuzz\Surfaces\AdminMediaChromeSurface::run_media_attach_action_child( $case );
PHP;
	}

	public static function run_media_attach_action_child( array $case ): void {
		ini_set( 'display_errors', '0' );
		self::prepare_runtime();

		$ctx      = new \ComponentFuzz\FuzzContext( (int) ( $case['seed'] ?? 1 ), self::NAME, (int) ( $case['iteration'] ?? 0 ) );
		$label    = (string) ( $case['label'] ?? 'media-attach' );
		$scenario = (string) ( $case['scenario'] ?? 'update' );
		$action   = 'detach' === (string) ( $case['action'] ?? 'attach' ) ? 'detach' : 'attach';
		$state    = array(
			'ok'                   => false,
			'label'                => $label,
			'scenario'             => $scenario,
			'action'               => $action,
			'parentId'             => 0,
			'parentArgument'       => 0,
			'oldParentId'          => 0,
			'allowedAttachmentIds' => array(),
			'deniedAttachmentId'   => 0,
			'allTrackedIds'        => array(),
			'allowedEditIds'       => array(),
			'requestMedia'         => array(),
			'parentsBefore'        => array(),
			'parentsAfter'         => array(),
			'queryCountBefore'     => 0,
			'queryDelta'           => array(),
			'rowsAffected'         => null,
			'redirects'            => array(),
			'dieCalls'             => array(),
			'actionEvents'         => array(),
			'cacheEvents'          => array(),
			'capEvents'            => array(),
			'returned'             => false,
			'throwable'            => null,
			'preReportOutput'      => '',
		);

		$buffer_level = ob_get_level();
		ob_start();

		register_shutdown_function(
			static function () use ( &$state, $buffer_level ): void {
				$output = '';
				while ( ob_get_level() > $buffer_level ) {
					$chunk = ob_get_clean();
					if ( is_string( $chunk ) ) {
						$output = $chunk . $output;
					}
				}

				$state['preReportOutput'] = $output;
				$state['parentsAfter']    = self::media_attach_action_post_state( array_map( 'intval', $state['allTrackedIds'] ) );

				$wpdb = $GLOBALS['wpdb'] ?? null;
				if ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ) {
					$queries               = $wpdb->component_fuzz_get_queries();
					$state['queryDelta']   = array_values( array_slice( $queries, (int) $state['queryCountBefore'] ) );
					$state['rowsAffected'] = (int) $wpdb->rows_affected;
				}

				$state['ok'] = null === $state['throwable'];
				echo json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
			}
		);

		try {
			$parent_id      = self::seed_parent_post( $ctx->fork( 'target-parent' ) );
			$old_parent     = self::seed_parent_post( $ctx->fork( 'old-parent' ) );
			$initial_parent = 'detach' === $action ? $parent_id : $old_parent;
			$allowed_one    = self::seed_attachment( $ctx->fork( 'allowed-one' ), 'image/jpeg', array( 'parent_id' => $initial_parent ) );
			$allowed_two    = self::seed_attachment( $ctx->fork( 'allowed-two' ), 'image/png', array( 'parent_id' => $initial_parent ) );
			$denied         = self::seed_attachment( $ctx->fork( 'denied' ), 'image/webp', array( 'parent_id' => $initial_parent ) );
			$parent_arg     = 'parent-zero' === $scenario ? 0 : $parent_id;
			$request_media  = array(
				(string) $allowed_one->ID,
				'not-a-media-id',
				(string) $denied->ID,
				'0',
				(string) $allowed_two->ID,
			);

			$state['parentId']             = $parent_id;
			$state['parentArgument']       = $parent_arg;
			$state['oldParentId']          = $old_parent;
			$state['allowedAttachmentIds'] = array( (int) $allowed_one->ID, (int) $allowed_two->ID );
			$state['deniedAttachmentId']   = (int) $denied->ID;
			$state['allTrackedIds']        = array( $parent_id, $old_parent, (int) $allowed_one->ID, (int) $allowed_two->ID, (int) $denied->ID );
			$state['requestMedia']         = $request_media;
			$state['parentsBefore']        = self::media_attach_action_post_state( $state['allTrackedIds'] );

			$_GET     = array( 'media' => $request_media );
			$_POST    = array();
			$_REQUEST = array( 'media' => $request_media );
			$_COOKIE  = array();

			$GLOBALS['pagenow']         = 'upload.php';
			$_SERVER['HTTP_HOST']       = 'example.test';
			$_SERVER['HTTPS']           = 'off';
			$_SERVER['PHP_SELF']        = '/wp-admin/upload.php';
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['REQUEST_URI']     = '/wp-admin/upload.php?component-fuzz-current=' . rawurlencode( $label );
			$_SERVER['HTTP_REFERER']    = (string) ( $case['referer'] ?? '' );
			$_SERVER['HTTP_USER_AGENT'] = 'component-fuzz/admin-media-attach';
			$_SERVER['REMOTE_ADDR']     = '198.51.100.42';
			$_SERVER['SERVER_PORT']     = '80';

			$allowed_edit_ids = array( (int) $allowed_one->ID, (int) $allowed_two->ID );
			if ( 'denied-parent' !== $scenario && 0 !== $parent_arg ) {
				$allowed_edit_ids[] = $parent_id;
			}
			$allowed_edit_map        = array_fill_keys( $allowed_edit_ids, true );
			$state['allowedEditIds'] = array_values( $allowed_edit_ids );
			$map_meta_cap_filter     = static function ( array $caps, string $cap, int $user_id, array $args ) use ( &$state, $allowed_edit_map ): array {
				if ( 'edit_post' !== $cap || ! isset( $args[0] ) ) {
					return $caps;
				}

				$post_id = (int) $args[0];
				$allowed = isset( $allowed_edit_map[ $post_id ] );
				$state['capEvents'][] = array(
					'cap'     => $cap,
					'userId'  => $user_id,
					'postId'  => $post_id,
					'allowed' => $allowed,
				);

				return $allowed ? array( 'exist' ) : array( 'do_not_allow' );
			};
			$user_has_cap_filter     = static function ( array $allcaps, array $caps, array $args, $user = null ): array {
				unset( $args, $user );
				foreach ( $caps as $cap ) {
					$allcaps[ $cap ] = 'do_not_allow' !== $cap;
				}
				return $allcaps;
			};
			$redirect_filter         = static function ( string $location, int $status ) use ( &$state ): string {
				$state['redirects'][] = array(
					'location' => $location,
					'status'   => $status,
				);
				return $location;
			};
			$die_handler_filter      = static function () use ( &$state ): callable {
				return static function ( $message = '', $title = '', $args = array() ) use ( &$state ): void {
					$state['dieCalls'][] = array(
						'message' => self::media_attach_action_die_message( $message ),
						'title'   => self::media_attach_action_die_message( $title ),
						'args'    => is_array( $args ) ? $args : array(),
					);
					exit;
				};
			};
			$media_action            = static function ( string $event_action, int $attachment_id, int $event_parent_id ) use ( &$state ): void {
				$state['actionEvents'][] = array(
					'action'       => $event_action,
					'attachmentId' => $attachment_id,
					'parentId'     => $event_parent_id,
				);
			};
			$cache_action            = static function ( int $attachment_id ) use ( &$state ): void {
				$state['cacheEvents'][] = array( 'attachmentId' => $attachment_id );
			};

			\add_filter( 'map_meta_cap', $map_meta_cap_filter, 10, 4 );
			\add_filter( 'user_has_cap', $user_has_cap_filter, 10, 4 );
			\add_filter( 'wp_redirect', $redirect_filter, PHP_INT_MAX, 2 );
			\add_filter( 'wp_die_handler', $die_handler_filter, PHP_INT_MAX );
			\add_action( 'wp_media_attach_action', $media_action, 10, 3 );
			\add_action( 'clean_attachment_cache', $cache_action, 10, 1 );

			\wp_set_current_user( 1 );
			if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof \WP_User ) {
				$GLOBALS['current_user']->allcaps = array( 'exist' => true );
			}

			$wpdb = $GLOBALS['wpdb'] ?? null;
			if ( $wpdb instanceof \Component_Fuzz_WPDB_Stub ) {
				$wpdb->rows_affected         = 0;
				$state['queryCountBefore'] = count( $wpdb->component_fuzz_get_queries() );
			}

			\wp_media_attach_action( $parent_arg, $action );
			$state['returned'] = true;
		} catch ( \Throwable $e ) {
			$state['throwable'] = self::describe_throwable( $e );
		}
	}

	private static function media_attach_action_child_result_has_expected_shape( array $result ): bool {
		return array_key_exists( 'ok', $result )
			&& array_key_exists( 'returned', $result )
			&& is_array( $result['redirects'] ?? null )
			&& is_array( $result['dieCalls'] ?? null )
			&& is_array( $result['actionEvents'] ?? null )
			&& is_array( $result['cacheEvents'] ?? null )
			&& is_array( $result['parentsBefore'] ?? null )
			&& is_array( $result['parentsAfter'] ?? null )
			&& is_array( $result['queryDelta'] ?? null );
	}

	private static function collect_media_attach_action_update_failures( array &$failures, array $case, array $result ): void {
		$action          = (string) ( $case['action'] ?? 'attach' );
		$allowed_ids     = array_map( 'intval', $result['allowedAttachmentIds'] ?? array() );
		$denied_id       = (int) ( $result['deniedAttachmentId'] ?? 0 );
		$parent_id       = (int) ( $result['parentId'] ?? 0 );
		$expected_parent = 'attach' === $action ? $parent_id : 0;
		$rows_affected   = (int) ( $result['rowsAffected'] ?? -1 );
		$parents_before  = is_array( $result['parentsBefore'] ?? null ) ? $result['parentsBefore'] : array();
		$parents_after   = is_array( $result['parentsAfter'] ?? null ) ? $result['parentsAfter'] : array();
		$updates         = self::media_attach_action_update_queries( $result['queryDelta'] ?? array() );
		$hook_ids        = array_map(
			static function ( array $event ): int {
				return (int) ( $event['attachmentId'] ?? 0 );
			},
			$result['actionEvents']
		);
		$cache_ids       = array_map(
			static function ( array $event ): int {
				return (int) ( $event['attachmentId'] ?? 0 );
			},
			$result['cacheEvents']
		);
		$redirects       = $result['redirects'];
		$redirect        = $redirects[0] ?? array();
		$query_args      = self::media_attach_action_url_query_args( (string) ( $redirect['location'] ?? '' ) );
		$key             = 'attach' === $action ? 'attached' : 'detach';
		$other_key       = 'attach' === $action ? 'detach' : 'attached';

		self::collect_failure(
			$failures,
			false === (bool) ( $result['returned'] ?? true ) && array() === ( $result['dieCalls'] ?? array() ),
			"{$case['label']} reaches wp_redirect() and exits without wp_die()",
			array(
				'returned' => $result['returned'] ?? null,
				'dieCalls' => $result['dieCalls'] ?? array(),
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $updates ) && self::media_attach_action_update_query_matches( (string) $updates[0], $expected_parent, $allowed_ids ),
			"{$case['label']} issues exactly one attachment post_parent UPDATE for allowed IDs",
			array(
				'updates'        => $updates,
				'expectedParent' => $expected_parent,
				'allowedIds'     => $allowed_ids,
			)
		);

		self::collect_failure(
			$failures,
			count( $allowed_ids ) === $rows_affected
				&& self::media_attach_action_post_parent_equals( $parents_after, $allowed_ids, $expected_parent )
				&& self::media_attach_action_parent_unchanged( $parents_before, $parents_after, array( $denied_id ) ),
			"{$case['label']} mutates only allowed attachment parents and reports changed row count",
			array(
				'rowsAffected'  => $rows_affected,
				'parentsBefore' => $parents_before,
				'parentsAfter'  => $parents_after,
			)
		);

		self::collect_failure(
			$failures,
			$allowed_ids === $hook_ids
				&& $allowed_ids === $cache_ids
				&& array() === array_filter(
					$result['actionEvents'],
					static function ( array $event ) use ( $action, $parent_id ): bool {
						return $action !== ( $event['action'] ?? null ) || $parent_id !== (int) ( $event['parentId'] ?? 0 );
					}
				),
			"{$case['label']} fires attach-action and cache-clean events once per allowed attachment",
			array(
				'actionEvents' => $result['actionEvents'],
				'cacheEvents'  => $result['cacheEvents'],
			)
		);

		self::collect_failure(
			$failures,
			1 === count( $redirects )
				&& 302 === (int) ( $redirect['status'] ?? 0 )
				&& isset( $query_args[ $key ] )
				&& (string) $rows_affected === (string) $query_args[ $key ]
				&& ! isset( $query_args[ $other_key ] )
				&& self::media_attach_action_preserved_query_matches( $case['preservedQuery'] ?? array(), $query_args ),
			"{$case['label']} redirects back to upload.php with stale attach/detach args replaced by result count",
			array(
				'redirect'  => $redirect,
				'queryArgs' => $query_args,
			)
		);
	}

	private static function collect_media_attach_action_noop_failures( array &$failures, array $case, array $result ): void {
		$label          = (string) $case['label'];
		$parents_before = is_array( $result['parentsBefore'] ?? null ) ? $result['parentsBefore'] : array();
		$parents_after  = is_array( $result['parentsAfter'] ?? null ) ? $result['parentsAfter'] : array();
		$tracked_ids    = array_map( 'intval', $result['allTrackedIds'] ?? array() );
		$updates        = self::media_attach_action_update_queries( $result['queryDelta'] ?? array() );
		$is_parent_zero = 'parent-zero' === ( $case['scenario'] ?? null );

		self::collect_failure(
			$failures,
			array() === $updates
				&& array() === ( $result['redirects'] ?? array() )
				&& array() === ( $result['actionEvents'] ?? array() )
				&& array() === ( $result['cacheEvents'] ?? array() )
				&& self::media_attach_action_parent_unchanged( $parents_before, $parents_after, $tracked_ids ),
			"{$label} does not update posts, redirect, fire hooks, or change parents",
			array(
				'updates'       => $updates,
				'redirects'     => $result['redirects'] ?? array(),
				'actionEvents'  => $result['actionEvents'] ?? array(),
				'cacheEvents'   => $result['cacheEvents'] ?? array(),
				'parentsBefore' => $parents_before,
				'parentsAfter'  => $parents_after,
			)
		);

		if ( $is_parent_zero ) {
			self::collect_failure(
				$failures,
				true === (bool) ( $result['returned'] ?? false )
					&& array() === ( $result['dieCalls'] ?? array() )
					&& array() === ( $result['capEvents'] ?? array() ),
				'parent-zero returns before permission checks or die handling',
				array(
					'returned'  => $result['returned'] ?? null,
					'dieCalls'  => $result['dieCalls'] ?? array(),
					'capEvents' => $result['capEvents'] ?? array(),
				)
			);
			return;
		}

		$die_calls = $result['dieCalls'] ?? array();
		$die_text  = (string) ( $die_calls[0]['message'] ?? '' );
		self::collect_failure(
			$failures,
			false === (bool) ( $result['returned'] ?? true )
				&& 1 === count( $die_calls )
				&& str_contains( $die_text, 'not allowed to edit this post' )
				&& 1 === count( $result['capEvents'] ?? array() )
				&& (int) ( $result['capEvents'][0]['postId'] ?? 0 ) === (int) ( $result['parentId'] ?? 0 ),
			'denied-parent dies before processing media IDs',
			array(
				'returned'  => $result['returned'] ?? null,
				'dieCalls'  => $die_calls,
				'capEvents' => $result['capEvents'] ?? array(),
			)
		);
	}

	private static function media_attach_action_update_queries( array $queries ): array {
		return array_values(
			array_filter(
				$queries,
				static function ( $query ): bool {
					return is_string( $query ) && preg_match( '/\bUPDATE\s+`?wp_posts`?\s+SET\s+`?post_parent`?\s*=/i', $query );
				}
			)
		);
	}

	private static function media_attach_action_update_query_matches( string $query, int $expected_parent, array $expected_ids ): bool {
		if ( ! preg_match( '/\bUPDATE\s+`?wp_posts`?\s+SET\s+`?post_parent`?\s*=\s*' . preg_quote( (string) $expected_parent, '/' ) . '\s+WHERE\s+`?post_type`?\s*=\s*\'attachment\'\s+AND\s+`?ID`?\s+IN\s*\(([^)]*)\)/i', $query, $matches ) ) {
			return false;
		}

		$actual_ids = array_map( 'intval', preg_split( '/\s*,\s*/', trim( $matches[1] ) ) ?: array() );
		sort( $actual_ids );
		$expected_ids = array_values( array_unique( array_map( 'intval', $expected_ids ) ) );
		sort( $expected_ids );

		return $expected_ids === $actual_ids;
	}

	private static function media_attach_action_url_query_args( string $url ): array {
		$query = parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return array();
		}

		$args = array();
		parse_str( $query, $args );
		return $args;
	}

	private static function media_attach_action_preserved_query_matches( array $expected, array $actual ): bool {
		foreach ( $expected as $key => $value ) {
			if ( (string) $value !== (string) ( $actual[ $key ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function media_attach_action_post_parent_equals( array $parents, array $ids, int $expected_parent ): bool {
		foreach ( $ids as $id ) {
			$key = (string) (int) $id;
			if ( $expected_parent !== (int) ( $parents[ $key ]['post_parent'] ?? -1 ) || 'attachment' !== ( $parents[ $key ]['post_type'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function media_attach_action_parent_unchanged( array $before, array $after, array $ids ): bool {
		foreach ( $ids as $id ) {
			$key = (string) (int) $id;
			if ( ( $before[ $key ]['post_parent'] ?? null ) !== ( $after[ $key ]['post_parent'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	private static function media_attach_action_post_state( array $ids ): array {
		$out = array();
		foreach ( array_values( array_unique( array_map( 'intval', $ids ) ) ) as $id ) {
			$post = \get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				$out[ (string) $id ] = null;
				continue;
			}

			$out[ (string) $id ] = array(
				'post_parent' => (int) $post->post_parent,
				'post_type'   => (string) $post->post_type,
			);
		}

		return $out;
	}

	private static function media_attach_action_die_message( $message ): string {
		if ( $message instanceof \WP_Error ) {
			return $message->get_error_message();
		}

		if ( is_scalar( $message ) || null === $message ) {
			$message = (string) $message;
		} else {
			$message = gettype( $message );
		}

		return function_exists( 'wp_strip_all_tags' ) ? \wp_strip_all_tags( $message ) : strip_tags( $message );
	}

	private static function seed_attachment( \ComponentFuzz\FuzzContext $ctx, string $mime, array $args = array() ): \WP_Post {
		$extension = (string) ( $args['extension'] ?? self::extension_for_mime( $mime ) );
		$parent_id = isset( $args['parent_id'] ) ? (int) $args['parent_id'] : self::seed_parent_post( $ctx->fork( 'parent' ) );
		$title     = (string) ( $args['post_title'] ?? 'Component fuzz attachment ' . $ctx->identifier( 3, 8 ) );
		$excerpt   = (string) ( $args['post_excerpt'] ?? 'Component fuzz caption ' . $ctx->text( 0, 18 ) );
		$content   = (string) ( $args['post_content'] ?? 'Component fuzz description ' . $ctx->text( 0, 18 ) );

		$post_id = \wp_insert_post(
			array_merge(
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
				array_intersect_key(
					$args,
					array_flip( array( 'post_author', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_name', 'post_status', 'guid', 'menu_order' ) )
				)
			),
			true
		);

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			throw new \RuntimeException( 'Could not seed attachment post.' );
		}

		$unsafe_suffix = ! empty( $args['unsafe_file'] ) ? '-unsafe-<script>alert(1)</script>' : '';
		$file_base     = 'component-fuzz-' . $post_id . $unsafe_suffix . '.' . $extension;
		$relative_file = (string) ( $args['relative_file'] ?? ( '2026/06/' . $file_base ) );
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
				'_SERVER',
				'body_id',
				'content_width',
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
