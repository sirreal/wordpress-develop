<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes image-edit request helpers, AJAX gates, and metadata side effects.
 */
final class MediaImageEditRequestsSurface {
	public const NAME = 'media-image-edit-requests';

	private const HOOK_SNAPSHOT_NAMES = array(
		'image_editor_save_pre',
		'image_edit_thumbnails_separately',
		'image_size_names_choose',
		'intermediate_image_sizes_advanced',
		'load_image_to_edit_filesystempath',
		'load_image_to_edit_path',
		'map_meta_cap',
		'pre_option_blog_charset',
		'site_icon_attachment_metadata',
		'status_header',
		'upload_dir',
		'wp_ajax_cropped_attachment_id',
		'wp_ajax_cropped_attachment_metadata',
		'wp_create_file_in_uploads',
		'wp_die_ajax_handler',
		'wp_die_handler',
		'wp_doing_ajax',
		'wp_image_editor_before_change',
		'wp_image_editors',
		'wp_save_image_editor_file',
	);

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		self::load_admin_includes();

		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'media-image-edit-requests.bootstrap-apis-available',
					'Required WordPress image-edit request APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		require_once __DIR__ . '/MediaImageEditRequestsFakeEditor.php';
		MediaImageEditRequestsFakeEditor::reset();

		$snapshot     = self::snapshot_state();
		$temp_root    = self::make_temp_root( $ctx );
		$cleanup_ok   = null === $temp_root;
		$cleanup_path = $temp_root;
		$rows         = array();

		try {
			if ( null === $temp_root ) {
				$rows[] = $ctx->skip(
					'media-image-edit-requests.temp-root.available',
					'Could not create an isolated temporary upload directory.',
					array( 'sysTempDir' => sys_get_temp_dir() )
				);
			} else {
				$rows[] = self::check_apply_changes_sequences( $ctx->fork( 'apply-changes' ) );
				$rows[] = self::check_save_file_and_stream_filters( $ctx->fork( 'save-stream' ), $temp_root );
				$rows[] = self::check_load_path_and_parent_copy( $ctx->fork( 'load-parent' ), $temp_root );
				$rows[] = self::check_crop_image_wrapper( $ctx->fork( 'crop-wrapper' ), $temp_root );
				$rows[] = self::check_preview_stream_and_ajax( $ctx->fork( 'preview' ), $temp_root );
				$rows[] = self::check_save_image_workflow( $ctx->fork( 'save-image' ), $temp_root );
				$rows[] = self::check_restore_image_metadata( $ctx->fork( 'restore-image' ), $temp_root );
				$rows[] = self::check_image_editor_ajax_request_matrix( $ctx->fork( 'image-editor-request-matrix' ), $temp_root );
				$rows[] = self::check_ajax_failure_envelopes( $ctx->fork( 'ajax-gates' ), $temp_root );
				$rows[] = self::check_crop_ajax_success_filters( $ctx->fork( 'crop-ajax' ), $temp_root );
				$rows[] = self::check_media_create_subsizes_ajax( $ctx->fork( 'subsizes-ajax' ), $temp_root );
			}
		} catch ( \Throwable $e ) {
			$rows[] = self::row(
				$ctx,
				'media-image-edit-requests.surface-no-throw',
				false,
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			if ( null !== $temp_root ) {
				self::remove_dir_recursive( $temp_root );
				$cleanup_ok = ! is_dir( $temp_root );
			}
			MediaImageEditRequestsFakeEditor::reset();
			self::restore_state( $snapshot );
		}

		$rows[] = self::row(
			$ctx,
			'media-image-edit-requests.cleanup.temp-files-and-globals',
			$cleanup_ok && self::state_restored( $snapshot ),
			array(
				'tempRoot'       => $cleanup_path,
				'tempCleaned'    => $cleanup_ok,
				'stateRestored'  => self::state_restored( $snapshot ),
				'bufferBalanced' => $snapshot['obLevel'] === ob_get_level(),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();
		foreach (
			array(
				'_load_image_to_edit_path',
				'check_ajax_referer',
				'current_user_can',
				'get_attached_file',
				'image_edit_apply_changes',
				'stream_preview_image',
				'wp_ajax_imgedit_preview',
				'wp_ajax_media_create_image_subsizes',
				'wp_ajax_crop_image',
				'wp_ajax_image_editor',
				'wp_copy_parent_attachment_properties',
				'wp_crop_image',
				'wp_get_attachment_metadata',
				'wp_get_image_editor',
				'wp_prepare_attachment_for_js',
				'wp_restore_image',
				'wp_save_image',
				'wp_save_image_file',
				'wp_stream_image',
				'wp_update_image_subsizes',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! class_exists( 'WP_Image_Editor' ) ) {
			$missing[] = 'class WP_Image_Editor';
		}

		return $missing;
	}

	private static function load_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/image-edit.php';
		require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
	}

	private static function check_apply_changes_sequences( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$filtered = array();
		$editor   = new MediaImageEditRequestsFakeEditor( 'component-fuzz-memory.jpg' );
		$editor->load();

		$editor_filter = static function ( $image, array $changes ) use ( &$filtered ) {
			$filtered[] = array_map( static fn( $operation ) => clone $operation, $changes );
			return $image;
		};

		$scale = $ctx->int( 125, 375 ) / 100;
		$crop  = (object) array(
			'x' => $ctx->int( 1, 23 ),
			'y' => $ctx->int( 1, 17 ),
			'w' => $ctx->int( 60, 220 ),
			'h' => $ctx->int( 40, 180 ),
			'r' => $scale,
		);
		$ops   = array(
			(object) array( 'r' => 90 ),
			(object) array( 'r' => 180 ),
			(object) array( 'f' => 1 ),
			(object) array( 'f' => 2 ),
			(object) array( 'c' => $crop ),
			(object) array(
				'type'  => 'rotate',
				'angle' => -90,
			),
		);

		\add_filter( 'wp_image_editor_before_change', $editor_filter, 10, 2 );
		try {
			$result = \image_edit_apply_changes( $editor, $ops );
		} finally {
			\remove_filter( 'wp_image_editor_before_change', $editor_filter, 10 );
		}

		$methods       = array_column( $editor->operations, 'method' );
		$crop_expected = array(
			'x' => (int) ( $crop->x * $scale ),
			'y' => (int) ( $crop->y * $scale ),
			'w' => (int) ( $crop->w * $scale ),
			'h' => (int) ( $crop->h * $scale ),
		);
		$crop_actual   = null;
		foreach ( $editor->operations as $operation ) {
			if ( 'crop' === $operation['method'] ) {
				$crop_actual = array_intersect_key( $operation, $crop_expected );
				break;
			}
		}

		self::collect_failure(
			$failures,
			$result === $editor
				&& array( 'load', 'rotate', 'flip', 'crop', 'rotate' ) === $methods
				&& 270 === (int) $editor->operations[1]['angle']
				&& true === $editor->operations[2]['horz']
				&& true === $editor->operations[2]['vert']
				&& $crop_expected === $crop_actual
				&& -90 === (int) $editor->operations[4]['angle'],
			'legacy rotate/flip/crop operations are expanded, combined, scaled, and applied in order',
			array(
				'operations'    => $editor->operations,
				'expectedCrop'  => $crop_expected,
				'filteredCount' => count( $filtered ),
			)
		);

		$filtered_types = isset( $filtered[0] ) ? array_map( static fn( $operation ) => $operation->type ?? null, $filtered[0] ) : array();
		self::collect_failure(
			$failures,
			array( 'rotate', 'flip', 'crop', 'rotate' ) === $filtered_types
				&& false === \has_filter( 'wp_image_editor_before_change', $editor_filter ),
			'before-change filter sees normalized operations and is restored',
			array(
				'filteredTypes' => $filtered_types,
				'hasFilter'     => \has_filter( 'wp_image_editor_before_change', $editor_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.apply-changes-sequences',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_save_file_and_stream_filters( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures       = array();
		$post_id        = 71000 + $ctx->int( 1, 999 );
		$save_path      = $temp_root . '/uploads/2026/06/filter-save-' . $ctx->identifier( 4, 8 ) . '.jpg';
		$pre_save_calls = array();
		$override_calls = array();
		$editor         = new MediaImageEditRequestsFakeEditor( $save_path );
		$editor->load();

		$pre_save_filter = static function ( $image, int $attachment_id ) use ( &$pre_save_calls ) {
			$pre_save_calls[] = array(
				'id'   => $attachment_id,
				'type' => is_object( $image ) ? get_class( $image ) : gettype( $image ),
			);
			return $image;
		};
		$override_filter = static function ( $saved, string $filename, $image, string $mime_type, int $attachment_id ) use ( &$override_calls ) {
			$bytes = 'component-fuzz-save-filter:' . $mime_type . ':' . $attachment_id;
			wp_mkdir_p( dirname( $filename ) );
			file_put_contents( $filename, $bytes );

			$override_calls[] = array(
				'id'       => $attachment_id,
				'filename' => $filename,
				'mime'     => $mime_type,
				'image'    => is_object( $image ) ? get_class( $image ) : gettype( $image ),
			);

			return array(
				'path'      => $filename,
				'file'      => wp_basename( $filename ),
				'width'     => 321,
				'height'    => 213,
				'mime-type' => $mime_type,
				'filesize'  => strlen( $bytes ),
			);
		};

		\add_filter( 'image_editor_save_pre', $pre_save_filter, 10, 2 );
		\add_filter( 'wp_save_image_editor_file', $override_filter, 10, 5 );
		ob_start();
		try {
			$saved   = \wp_save_image_file( $save_path, $editor, 'image/jpeg', $post_id );
			$stream  = \wp_stream_image( $editor, 'image/jpeg', $post_id );
			$output  = ob_get_clean();
			$cleaned = true;
		} finally {
			if ( ! isset( $cleaned ) && ob_get_level() > 0 ) {
				$output = ob_get_clean();
			}
			\remove_filter( 'image_editor_save_pre', $pre_save_filter, 10 );
			\remove_filter( 'wp_save_image_editor_file', $override_filter, 10 );
		}

		self::collect_failure(
			$failures,
			is_array( $saved )
				&& $save_path === $saved['path']
				&& 'filter-save-' === substr( $saved['file'], 0, 12 )
				&& 321 === (int) $saved['width']
				&& 213 === (int) $saved['height']
				&& file_exists( $save_path )
				&& str_starts_with( realpath( $save_path ), realpath( $temp_root ) ),
			'wp_save_image_file honors editor save filters and writes only under the temp root',
			array(
				'saved'     => $saved,
				'savePath'  => $save_path,
				'overrides' => $override_calls,
			)
		);

		self::collect_failure(
			$failures,
			true === $stream
				&& str_contains( $output, 'component-fuzz-stream:image/jpeg' )
				&& 2 === count( $pre_save_calls )
				&& 1 === count( $override_calls )
				&& false === \has_filter( 'image_editor_save_pre', $pre_save_filter )
				&& false === \has_filter( 'wp_save_image_editor_file', $override_filter ),
			'wp_stream_image reuses the save-pre filter and filters are restored after save/stream',
			array(
				'output'       => $output,
				'preSaveCalls' => $pre_save_calls,
				'hasSavePre'   => \has_filter( 'image_editor_save_pre', $pre_save_filter ),
				'hasOverride'  => \has_filter( 'wp_save_image_editor_file', $override_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.save-file-stream-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_load_path_and_parent_copy( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$filter_calls  = array();
		$path_filter   = static function ( string $path, int $attachment_id, $size ) use ( &$filter_calls ): string {
			$filter_calls[] = array(
				'id'   => $attachment_id,
				'path' => $path,
				'size' => $size,
			);
			return $path;
		};

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'load_image_to_edit_filesystempath', $path_filter, 10, 3 );
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'load-parent', array( 'withSizes' => true ) );
			$thumb   = \_load_image_to_edit_path( $fixture['id'], 'thumbnail' );
			$full    = \_load_image_to_edit_path( $fixture['id'], 'full' );
			$cropped = $fixture['dir'] . '/cropped-' . wp_basename( $fixture['file'] );
			file_put_contents( $cropped, 'component-fuzz-cropped-parent' );

			$attachment = \wp_copy_parent_attachment_properties( $cropped, $fixture['id'], 'component_fuzz_context' );
		} finally {
			\remove_filter( 'load_image_to_edit_filesystempath', $path_filter, 10 );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		self::collect_failure(
			$failures,
			isset( $fixture )
				&& $fixture['dir'] . '/' . $fixture['metadata']['sizes']['thumbnail']['file'] === $thumb
				&& $fixture['file'] === $full
				&& 1 === count( $filter_calls )
				&& 'thumbnail' === $filter_calls[0]['size']
				&& false === \has_filter( 'load_image_to_edit_filesystempath', $path_filter ),
			'_load_image_to_edit_path resolves full and intermediate files with bounded filesystem filter calls',
			array(
				'thumb'       => $thumb ?? null,
				'full'        => $full ?? null,
				'filterCalls' => $filter_calls,
			)
		);

		self::collect_failure(
			$failures,
			isset( $attachment, $fixture )
				&& $fixture['post']['post_title'] === $attachment['post_title']
				&& $fixture['post']['post_content'] === $attachment['post_content']
				&& $fixture['post']['post_excerpt'] === ( $attachment['post_excerpt'] ?? null )
				&& $fixture['id'] === (int) $attachment['post_parent']
				&& 'component_fuzz_context' === $attachment['context']
				&& isset( $attachment['meta_input']['_wp_attachment_image_alt'] )
				&& ! str_contains( $attachment['guid'], '<script' ),
			'wp_copy_parent_attachment_properties preserves parent title/content/caption/alt while deriving a safe cropped URL',
			array( 'attachment' => $attachment ?? null )
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.load-path-parent-copy',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_crop_image_wrapper( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$editor_filter = self::fake_editor_filter();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'crop-wrapper' );
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $fixture['file'] ] = array(
				'width'  => 960,
				'height' => 640,
			);

			$crop = array(
				'x'       => $ctx->int( 0, 41 ),
				'y'       => $ctx->int( 0, 37 ),
				'w'       => $ctx->int( 80, 220 ),
				'h'       => $ctx->int( 60, 180 ),
				'dst_w'   => $ctx->int( 40, 120 ),
				'dst_h'   => $ctx->int( 40, 120 ),
				'src_abs' => $ctx->bool(),
			);
			$result = \wp_crop_image( $fixture['id'], $crop['x'], $crop['y'], $crop['w'], $crop['h'], $crop['dst_w'], $crop['dst_h'], $crop['src_abs'] );
		} finally {
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		$editor     = MediaImageEditRequestsFakeEditor::$instances[0] ?? null;
		$crop_call  = self::first_operation( $editor, 'crop' );
		$save_call  = self::first_operation( $editor, 'save' );
		$result_dir = is_string( $result ?? null ) ? realpath( dirname( $result ) ) : false;

		self::collect_failure(
			$failures,
			is_string( $result ?? null )
				&& file_exists( $result )
				&& false !== $result_dir
				&& str_starts_with( $result_dir, realpath( $temp_root ) )
				&& str_starts_with( wp_basename( $result ), 'cropped-' )
				&& isset( $crop_call, $save_call )
				&& $crop['x'] === $crop_call['x']
				&& $crop['y'] === $crop_call['y']
				&& $crop['w'] === $crop_call['w']
				&& $crop['h'] === $crop_call['h']
				&& $crop['dst_w'] === $crop_call['dst_w']
				&& $crop['dst_h'] === $crop_call['dst_h']
				&& $crop['src_abs'] === $crop_call['abs']
				&& false === \has_filter( 'wp_image_editors', $editor_filter ),
			'wp_crop_image delegates exact crop arguments to the editor and saves a unique temp-root file',
			array(
				'result'     => $result ?? null,
				'crop'       => $crop,
				'cropCall'   => $crop_call,
				'saveCall'   => $save_call,
				'hasEditors' => \has_filter( 'wp_image_editors', $editor_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.crop-image-wrapper',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_preview_stream_and_ajax( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$editor_filter = self::fake_editor_filter();
		$cap_filter    = self::cap_grant_filter( array( 'edit_post' ) );
		$superglobals  = self::snapshot_superglobals();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		\add_filter( 'map_meta_cap', $cap_filter, 10, 4 );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'preview', array( 'withSizes' => true ) );
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $fixture['file'] ] = array(
				'width'  => 1800,
				'height' => 900,
			);
			$history = array(
				(object) array( 'r' => 90 ),
				(object) array(
					'c' => (object) array(
						'x' => 2,
						'y' => 4,
						'w' => 300,
						'h' => 200,
						'r' => 2,
					),
				),
			);

			$_REQUEST = array( 'history' => wp_json_encode( $history ) );
			ob_start();
			$direct_preview = \stream_preview_image( $fixture['id'] );
			$direct_output  = ob_get_clean();
			$direct_editor  = MediaImageEditRequestsFakeEditor::$instances[ count( MediaImageEditRequestsFakeEditor::$instances ) - 1 ] ?? null;

			$user_id = $ctx->int( 1, 99999 );
			\wp_set_current_user( $user_id );
			$nonce    = \wp_create_nonce( 'image_editor-' . $fixture['id'] );
			$_GET     = array(
				'postid'       => (string) $fixture['id'],
				'_ajax_nonce'  => $nonce,
				'history'      => wp_json_encode( $history ),
			);
			$_REQUEST = $_GET;
			$ajax_preview = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_imgedit_preview();
				},
				true
			);
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'map_meta_cap', $cap_filter, 10 );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		$resize_call = self::first_operation( $direct_editor ?? null, 'resize' );
		self::collect_failure(
			$failures,
			true === ( $direct_preview ?? null )
				&& str_contains( $direct_output ?? '', 'component-fuzz-stream:image/jpeg' )
				&& isset( $resize_call )
				&& max( (int) $resize_call['width'], (int) $resize_call['height'] ) <= 600,
			'stream_preview_image applies history, scales preview max dimension to 600, and streams through the fake editor',
			array(
				'directPreview' => $direct_preview ?? null,
				'directOutput'  => $direct_output ?? null,
				'operations'    => $direct_editor->operations ?? null,
				'resizeCall'    => $resize_call,
			)
		);

		self::collect_failure(
			$failures,
			isset( $ajax_preview )
				&& $ajax_preview['captured']
				&& str_contains( self::terminal_body( $ajax_preview ), 'component-fuzz-stream:image/jpeg' )
				&& 1 === count( $ajax_preview['dieCalls'] )
				&& '' === (string) ( $ajax_preview['dieCalls'][0]['message'] ?? '' )
				&& $ajax_preview['filtersRestored']
				&& $ajax_preview['bufferBalanced']
				&& false === \has_filter( 'map_meta_cap', $cap_filter )
				&& false === \has_filter( 'wp_image_editors', $editor_filter ),
			'wp_ajax_imgedit_preview enforces nonce/capability, streams preview bytes, and terminates with captured wp_die',
			array(
				'capture'    => $ajax_preview ?? null,
				'hasCap'     => \has_filter( 'map_meta_cap', $cap_filter ),
				'hasEditors' => \has_filter( 'wp_image_editors', $editor_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.preview-stream-ajax',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_save_image_workflow( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$editor_filter = self::fake_editor_filter();
		$superglobals  = self::snapshot_superglobals();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'save-image', array( 'withSizes' => true ) );
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $fixture['file'] ] = array(
				'width'  => $fixture['metadata']['width'],
				'height' => $fixture['metadata']['height'],
			);

			$_REQUEST = array(
				'target' => 'full',
			);
			$no_change = \wp_save_image( $fixture['id'] );

			$_REQUEST = array(
				'do'      => 'scale',
				'fwidth'  => (string) ( $fixture['metadata']['width'] + 100 ),
				'fheight' => (string) ( $fixture['metadata']['height'] + 100 ),
				'target'  => 'full',
			);
			$oversized = \wp_save_image( $fixture['id'] );

			$history = array(
				(object) array( 'r' => 90 ),
				(object) array(
					'c' => (object) array(
						'x' => 3,
						'y' => 5,
						'w' => 220,
						'h' => 140,
						'r' => 2,
					),
				),
			);
			$_REQUEST = array(
				'history' => wp_json_encode( $history ),
				'target'  => 'full',
			);
			$saved = \wp_save_image( $fixture['id'] );

			$updated_file    = \get_attached_file( $fixture['id'] );
			$updated_meta    = \wp_get_attachment_metadata( $fixture['id'], true );
			$backup_sizes    = \get_post_meta( $fixture['id'], '_wp_attachment_backup_sizes', true );
			$editor_ops      = array_map(
				static fn( MediaImageEditRequestsFakeEditor $editor ): array => $editor->operations,
				MediaImageEditRequestsFakeEditor::$instances
			);
			$updated_realpath = is_string( $updated_file ) && file_exists( $updated_file ) ? realpath( $updated_file ) : false;
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		self::collect_failure(
			$failures,
			! empty( $no_change->error )
				&& ! empty( $oversized->error )
				&& ! empty( $saved->msg )
				&& empty( $saved->error )
				&& is_string( $updated_file ?? null )
				&& preg_match( '/-e[0-9]{13}\.jpg$/', wp_basename( $updated_file ) )
				&& false !== $updated_realpath
				&& str_starts_with( $updated_realpath, realpath( $temp_root ) ),
			'wp_save_image rejects no-op and oversized scale requests, then writes edited full image under temp uploads',
			array(
				'noChange'    => $no_change ?? null,
				'oversized'   => $oversized ?? null,
				'saved'       => $saved ?? null,
				'updatedFile' => $updated_file ?? null,
				'editorOps'   => $editor_ops ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $updated_meta ?? null )
				&& is_array( $backup_sizes ?? null )
				&& isset( $backup_sizes['full-orig'] )
				&& $fixture['metadata']['width'] === (int) $backup_sizes['full-orig']['width']
				&& $fixture['metadata']['height'] === (int) $backup_sizes['full-orig']['height']
				&& wp_basename( $fixture['file'] ) === $backup_sizes['full-orig']['file']
				&& str_ends_with( (string) $updated_meta['file'], wp_basename( $updated_file ?? '' ) )
				&& isset( $updated_meta['filesize'] )
				&& (int) $updated_meta['filesize'] > 0
				&& false === \has_filter( 'wp_image_editors', $editor_filter ),
			'wp_save_image updates attached-file metadata and backup sizes coherently after a history edit',
			array(
				'metadata'    => $updated_meta ?? null,
				'backupSizes' => $backup_sizes ?? null,
				'hasEditors'  => \has_filter( 'wp_image_editors', $editor_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.save-image-workflow',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_restore_image_metadata( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );

		\add_filter( 'upload_dir', $upload_filter );
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'restore-image', array( 'edited' => true ) );
			$backup  = array(
				'full-orig'      => array(
					'file'     => 'restore-image-original.jpg',
					'width'    => 1600,
					'height'   => 900,
					'filesize' => 31,
				),
				'thumbnail-orig' => array(
					'file'      => 'restore-image-thumbnail.jpg',
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
					'filesize'  => 17,
				),
			);
			file_put_contents( $fixture['dir'] . '/restore-image-original.jpg', 'component-fuzz-restore-original' );
			file_put_contents( $fixture['dir'] . '/restore-image-thumbnail.jpg', 'component-fuzz-restore-thumbnail' );
			\update_post_meta( $fixture['id'], '_wp_attachment_backup_sizes', $backup );

			$message       = \wp_restore_image( $fixture['id'] );
			$restored_file = \get_attached_file( $fixture['id'] );
			$metadata      = \wp_get_attachment_metadata( $fixture['id'], true );
			$backup_after  = \get_post_meta( $fixture['id'], '_wp_attachment_backup_sizes', true );
		} finally {
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		$timestamped_full_backups = array();
		if ( is_array( $backup_after ?? null ) ) {
			foreach ( array_keys( $backup_after ) as $key ) {
				if ( preg_match( '/^full-[0-9]{13}$/', (string) $key ) ) {
					$timestamped_full_backups[] = $key;
				}
			}
		}

		self::collect_failure(
			$failures,
			isset( $message->msg )
				&& is_string( $restored_file ?? null )
				&& str_ends_with( $restored_file, '/restore-image-original.jpg' )
				&& is_array( $metadata ?? null )
				&& '2026/06/restore-image-original.jpg' === $metadata['file']
				&& 1600 === (int) $metadata['width']
				&& 900 === (int) $metadata['height']
				&& 31 === (int) $metadata['filesize']
				&& isset( $metadata['sizes']['thumbnail'] )
				&& ! isset( $metadata['sizes']['medium'] )
				&& 1 === count( $timestamped_full_backups ),
			'wp_restore_image restores full/thumbnail backup metadata and preserves the edited full backup when not overwriting',
			array(
				'message'       => $message ?? null,
				'restoredFile'  => $restored_file ?? null,
				'metadata'      => $metadata ?? null,
				'backupAfter'   => $backup_after ?? null,
				'timestampKeys' => $timestamped_full_backups,
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.restore-image-metadata',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_image_editor_ajax_request_matrix( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$editor_filter = self::fake_editor_filter();
		$cap_filter    = self::cap_grant_filter( array( 'edit_post' ) );
		$superglobals  = self::snapshot_superglobals();
		$fixture_ids   = array();
		$result        = array();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$denied_fixture = self::seed_attachment_fixture( $ctx->fork( 'denied-fixture' ), $temp_root, 'image-editor-denied' );
			$fixture_ids[]  = $denied_fixture['id'];
			\wp_set_current_user( 0 );
			$_POST    = array(
				'postid' => (string) $denied_fixture['id'],
				'do'     => 'save',
			);
			$_REQUEST = $_POST;
			$result['denied'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);

			\add_filter( 'map_meta_cap', $cap_filter, 10, 4 );
			\wp_set_current_user( $ctx->int( 1, 99999 ) );

			$bad_nonce_fixture = self::seed_attachment_fixture( $ctx->fork( 'bad-nonce-fixture' ), $temp_root, 'image-editor-bad-nonce' );
			$fixture_ids[]     = $bad_nonce_fixture['id'];
			$_POST             = array(
				'_ajax_nonce' => 'bad-' . $ctx->identifier( 3, 8 ),
				'postid'      => (string) $bad_nonce_fixture['id'],
				'do'          => 'save',
			);
			$_REQUEST          = $_POST;
			$result['badNonce'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);

			$save_fixture = self::seed_attachment_fixture( $ctx->fork( 'save-fixture' ), $temp_root, 'image-editor-save', array( 'withSizes' => true ) );
			$fixture_ids[] = $save_fixture['id'];
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $save_fixture['file'] ] = array(
				'width'  => $save_fixture['metadata']['width'],
				'height' => $save_fixture['metadata']['height'],
			);
			$history = array(
				(object) array( 'r' => 90 ),
				(object) array(
					'c' => (object) array(
						'x' => $ctx->int( 1, 15 ),
						'y' => $ctx->int( 1, 15 ),
						'w' => $ctx->int( 180, 260 ),
						'h' => $ctx->int( 120, 220 ),
						'r' => 2,
					),
				),
			);
			$_POST  = array(
				'_ajax_nonce' => \wp_create_nonce( 'image_editor-' . $save_fixture['id'] ),
				'postid'      => (string) $save_fixture['id'],
				'do'          => 'save',
				'history'     => \wp_json_encode( $history ),
				'target'      => 'full',
				'context'     => 'edit-attachment',
			);
			$_REQUEST = $_POST;
			$result['save'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);
			$result['saveJson']    = json_decode( self::terminal_body( $result['save'] ), true );
			$result['saveFile']    = \get_attached_file( $save_fixture['id'] );
			$result['saveMeta']    = \wp_get_attachment_metadata( $save_fixture['id'], true );
			$result['saveBackups'] = \get_post_meta( $save_fixture['id'], '_wp_attachment_backup_sizes', true );
			$result['saveOps']     = array_map(
				static fn( MediaImageEditRequestsFakeEditor $editor ): array => $editor->operations,
				MediaImageEditRequestsFakeEditor::$instances
			);

			$scale_fixture = self::seed_attachment_fixture( $ctx->fork( 'scale-fixture' ), $temp_root, 'image-editor-scale' );
			$fixture_ids[] = $scale_fixture['id'];
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $scale_fixture['file'] ] = array(
				'width'  => $scale_fixture['metadata']['width'],
				'height' => $scale_fixture['metadata']['height'],
			);
			$_POST  = array(
				'_ajax_nonce' => \wp_create_nonce( 'image_editor-' . $scale_fixture['id'] ),
				'postid'      => (string) $scale_fixture['id'],
				'do'          => 'scale',
				'fwidth'      => (string) ( $scale_fixture['metadata']['width'] + $ctx->int( 1, 30 ) ),
				'fheight'     => (string) ( $scale_fixture['metadata']['height'] + $ctx->int( 1, 30 ) ),
				'target'      => 'full',
			);
			$_REQUEST = $_POST;
			$result['scaleError'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);
			$result['scaleJson'] = json_decode( self::terminal_body( $result['scaleError'] ), true );

			$restore_fixture = self::seed_attachment_fixture( $ctx->fork( 'restore-fixture' ), $temp_root, 'image-editor-restore', array( 'edited' => true ) );
			$fixture_ids[]   = $restore_fixture['id'];
			$restore_backup  = array(
				'full-orig'      => array(
					'file'     => 'image-editor-restore-original.jpg',
					'width'    => 1400,
					'height'   => 875,
					'filesize' => 37,
				),
				'thumbnail-orig' => array(
					'file'      => 'image-editor-restore-thumbnail.jpg',
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
					'filesize'  => 19,
				),
			);
			file_put_contents( $restore_fixture['dir'] . '/image-editor-restore-original.jpg', 'component-fuzz-ajax-restore-original' );
			file_put_contents( $restore_fixture['dir'] . '/image-editor-restore-thumbnail.jpg', 'component-fuzz-ajax-restore-thumbnail' );
			\update_post_meta( $restore_fixture['id'], '_wp_attachment_backup_sizes', $restore_backup );
			$_POST    = array(
				'_ajax_nonce' => \wp_create_nonce( 'image_editor-' . $restore_fixture['id'] ),
				'postid'      => (string) $restore_fixture['id'],
				'do'          => 'restore',
			);
			$_REQUEST = $_POST;
			$result['restore'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);
			$result['restoreJson'] = json_decode( self::terminal_body( $result['restore'] ), true );
			$result['restoreFile'] = \get_attached_file( $restore_fixture['id'] );
			$result['restoreMeta'] = \wp_get_attachment_metadata( $restore_fixture['id'], true );

			$unknown_fixture = self::seed_attachment_fixture( $ctx->fork( 'unknown-fixture' ), $temp_root, 'image-editor-unknown', array( 'withSizes' => true ) );
			$fixture_ids[]   = $unknown_fixture['id'];
			$_POST           = array(
				'_ajax_nonce' => \wp_create_nonce( 'image_editor-' . $unknown_fixture['id'] ),
				'postid'      => (string) $unknown_fixture['id'],
				'do'          => 'cfz-' . $ctx->identifier( 3, 8 ),
			);
			$_REQUEST        = $_POST;
			$result['unknown'] = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);
			$result['unknownJson'] = json_decode( self::terminal_body( $result['unknown'] ), true );
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'map_meta_cap', $cap_filter, 10 );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			self::delete_fixtures( $fixture_ids );
		}

		$save_file_realpath    = is_string( $result['saveFile'] ?? null ) && file_exists( $result['saveFile'] ) ? realpath( $result['saveFile'] ) : false;
		$save_methods          = array();
		foreach ( $result['saveOps'] ?? array() as $operations ) {
			foreach ( $operations as $operation ) {
				$save_methods[] = $operation['method'] ?? null;
			}
		}

		self::collect_failure(
			$failures,
			isset( $result['denied'], $result['badNonce'] )
				&& $result['denied']['captured']
				&& '-1' === trim( self::terminal_body( $result['denied'] ) )
				&& $result['denied']['filtersRestored']
				&& $result['badNonce']['captured']
				&& '-1' === trim( self::terminal_body( $result['badNonce'] ) )
				&& $result['badNonce']['filtersRestored'],
			'image editor AJAX fails closed before mutation for denied capabilities and invalid nonces',
			array(
				'denied'   => $result['denied'] ?? null,
				'badNonce' => $result['badNonce'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $result['saveJson'] ?? null )
				&& true === ( $result['saveJson']['success'] ?? null )
				&& isset( $result['saveJson']['data']['msg'] )
				&& false !== $save_file_realpath
				&& str_starts_with( $save_file_realpath, realpath( $temp_root ) )
				&& preg_match( '/-e[0-9]{13}\.jpg$/', wp_basename( (string) ( $result['saveFile'] ?? '' ) ) )
				&& is_array( $result['saveMeta'] ?? null )
				&& str_ends_with( (string) $result['saveMeta']['file'], wp_basename( (string) ( $result['saveFile'] ?? '' ) ) )
				&& is_array( $result['saveBackups'] ?? null )
				&& isset( $result['saveBackups']['full-orig'] )
				&& in_array( 'rotate', $save_methods, true )
				&& in_array( 'crop', $save_methods, true )
				&& in_array( 'save', $save_methods, true )
				&& ( $result['save'] ?? array() )['captured']
				&& ( $result['save'] ?? array() )['filtersRestored'],
			'image editor AJAX save accepts generated history, writes edited image metadata, and returns a success JSON message',
			array(
				'json'      => $result['saveJson'] ?? null,
				'file'      => $result['saveFile'] ?? null,
				'meta'      => $result['saveMeta'] ?? null,
				'backups'   => $result['saveBackups'] ?? null,
				'methods'   => $save_methods,
				'capture'   => $result['save'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $result['scaleJson'] ?? null )
				&& false === ( $result['scaleJson']['success'] ?? null )
				&& isset( $result['scaleJson']['data']['message']['error'] )
				&& isset( $result['scaleJson']['data']['html'] )
				&& str_contains( (string) $result['scaleJson']['data']['html'], 'imgedit-panel-content' )
				&& ( $result['scaleError'] ?? array() )['captured']
				&& ( $result['scaleError'] ?? array() )['filtersRestored'],
			'image editor AJAX scale rejects oversized requests with an error envelope plus refreshed editor HTML',
			array(
				'json'    => $result['scaleJson'] ?? null,
				'capture' => $result['scaleError'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $result['restoreJson'] ?? null )
				&& true === ( $result['restoreJson']['success'] ?? null )
				&& isset( $result['restoreJson']['data']['message']['msg'] )
				&& is_string( $result['restoreFile'] ?? null )
				&& str_ends_with( $result['restoreFile'], '/image-editor-restore-original.jpg' )
				&& is_array( $result['restoreMeta'] ?? null )
				&& '2026/06/image-editor-restore-original.jpg' === ( $result['restoreMeta']['file'] ?? null )
				&& 1400 === (int) ( $result['restoreMeta']['width'] ?? 0 )
				&& 875 === (int) ( $result['restoreMeta']['height'] ?? 0 )
				&& isset( $result['restoreJson']['data']['html'] )
				&& str_contains( (string) $result['restoreJson']['data']['html'], 'imgedit-panel-content' )
				&& ( $result['restore'] ?? array() )['captured']
				&& ( $result['restore'] ?? array() )['filtersRestored'],
			'image editor AJAX restore rehydrates original metadata and returns refreshed editor HTML',
			array(
				'json'    => $result['restoreJson'] ?? null,
				'file'    => $result['restoreFile'] ?? null,
				'meta'    => $result['restoreMeta'] ?? null,
				'capture' => $result['restore'] ?? null,
			)
		);

		self::collect_failure(
			$failures,
			is_array( $result['unknownJson'] ?? null )
				&& true === ( $result['unknownJson']['success'] ?? null )
				&& false === ( $result['unknownJson']['data']['message'] ?? null )
				&& isset( $result['unknownJson']['data']['html'] )
				&& str_contains( (string) $result['unknownJson']['data']['html'], 'imgedit-panel-content' )
				&& ( $result['unknown'] ?? array() )['captured']
				&& ( $result['unknown'] ?? array() )['filtersRestored']
				&& self::snapshot_superglobals() === $superglobals
				&& false === \has_filter( 'map_meta_cap', $cap_filter )
				&& false === \has_filter( 'wp_image_editors', $editor_filter )
				&& false === \has_filter( 'upload_dir', $upload_filter ),
			'image editor AJAX unknown actions render a no-mutation editor refresh and restore request/filter state',
			array(
				'json'       => $result['unknownJson'] ?? null,
				'capture'    => $result['unknown'] ?? null,
				'hasFilters' => array(
					'cap'     => \has_filter( 'map_meta_cap', $cap_filter ),
					'editors' => \has_filter( 'wp_image_editors', $editor_filter ),
					'upload'  => \has_filter( 'upload_dir', $upload_filter ),
				),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.image-editor-ajax-request-matrix',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 6 ) )
		);
	}

	private static function check_ajax_failure_envelopes( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$superglobals  = self::snapshot_superglobals();

		\add_filter( 'upload_dir', $upload_filter );
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'ajax-gates' );

			$_POST = array( 'postid' => (string) $fixture['id'] );
			$_REQUEST = $_POST;
			\wp_set_current_user( 0 );
			$image_editor_denied = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_image_editor();
				},
				true
			);

			$nonce = \wp_create_nonce( 'image_editor-' . $fixture['id'] );
			$_POST = array(
				'id'          => (string) $fixture['id'],
				'nonce'       => $nonce,
				'context'     => 'custom_logo',
				'cropDetails' => array(
					'x1'         => '7',
					'y1'         => '9',
					'width'      => '120',
					'height'     => '80',
					'dst_width'  => '60',
					'dst_height' => '40',
				),
			);
			$_REQUEST = $_POST;
			$crop_denied = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_crop_image();
				},
				true
			);
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		$crop_json = json_decode( self::terminal_body( $crop_denied ?? array() ), true );
		self::collect_failure(
			$failures,
			isset( $image_editor_denied, $crop_denied )
				&& $image_editor_denied['captured']
				&& '-1' === trim( self::terminal_body( $image_editor_denied ) )
				&& 1 === count( $image_editor_denied['dieCalls'] )
				&& $crop_denied['captured']
				&& is_array( $crop_json )
				&& false === ( $crop_json['success'] ?? null )
				&& $image_editor_denied['filtersRestored']
				&& $crop_denied['filtersRestored']
				&& $image_editor_denied['bufferBalanced']
				&& $crop_denied['bufferBalanced'],
			'AJAX image editor and crop handlers fail closed with captured wp_die/JSON envelopes when capabilities are absent',
			array(
				'imageEditor' => $image_editor_denied ?? null,
				'crop'        => $crop_denied ?? null,
				'cropJson'    => $crop_json,
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.ajax-failure-envelopes',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_crop_ajax_success_filters( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures          = array();
		$upload_filter     = self::upload_dir_filter( $temp_root );
		$editor_filter     = self::fake_editor_filter();
		$cap_filter        = self::cap_grant_filter( array( 'edit_post' ) );
		$created_files     = array();
		$metadata_filters  = array();
		$attachment_filter = array();
		$superglobals      = self::snapshot_superglobals();

		$file_filter = static function ( string $file, int $attachment_id ) use ( &$created_files ): string {
			$created_files[] = array(
				'id'   => $attachment_id,
				'file' => $file,
			);
			return $file;
		};
		$metadata_filter = static function ( array $metadata ) use ( &$metadata_filters ): array {
			$metadata['component_fuzz_cropped'] = true;
			$metadata_filters[]                 = array_keys( $metadata );
			return $metadata;
		};
		$id_filter       = static function ( int $attachment_id, string $context ) use ( &$attachment_filter ): int {
			$attachment_filter[] = array(
				'id'      => $attachment_id,
				'context' => $context,
			);
			return $attachment_id;
		};

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		\add_filter( 'map_meta_cap', $cap_filter, 10, 4 );
		\add_filter( 'wp_create_file_in_uploads', $file_filter, 10, 2 );
		\add_filter( 'wp_ajax_cropped_attachment_metadata', $metadata_filter );
		\add_filter( 'wp_ajax_cropped_attachment_id', $id_filter, 10, 2 );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'crop-ajax', array( 'withSizes' => true ) );
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $fixture['file'] ] = array(
				'width'  => 1024,
				'height' => 768,
			);
			$user_id = $ctx->int( 1, 99999 );
			\wp_set_current_user( $user_id );

			$_POST = array(
				'id'          => (string) $fixture['id'],
				'nonce'       => \wp_create_nonce( 'image_editor-' . $fixture['id'] ),
				'context'     => 'custom_logo',
				'cropDetails' => array(
					'x1'         => '-7',
					'y1'         => '11',
					'width'      => '320',
					'height'     => '210',
					'dst_width'  => '160',
					'dst_height' => '105',
				),
			);
			$_REQUEST = $_POST;
			$capture  = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_crop_image();
				},
				true
			);
			$decoded  = json_decode( self::terminal_body( $capture ), true );
			$new_id   = (int) ( $attachment_filter[0]['id'] ?? 0 );
			$new_meta = $new_id > 0 ? \wp_get_attachment_metadata( $new_id, true ) : null;
			$new_post = $new_id > 0 ? \get_post( $new_id ) : null;
			$post_ids = array_filter( array( $fixture['id'], $new_id ) );
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'wp_ajax_cropped_attachment_id', $id_filter, 10 );
			\remove_filter( 'wp_ajax_cropped_attachment_metadata', $metadata_filter );
			\remove_filter( 'wp_create_file_in_uploads', $file_filter, 10 );
			\remove_filter( 'map_meta_cap', $cap_filter, 10 );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $post_ids ) ) {
				self::delete_fixtures( $post_ids );
			}
		}

		$crop_editor = MediaImageEditRequestsFakeEditor::$instances[0] ?? null;
		$crop_call   = self::first_operation( $crop_editor, 'crop' );
		self::collect_failure(
			$failures,
			isset( $decoded )
				&& true === ( $decoded['success'] ?? null )
				&& is_array( $decoded['data'] ?? null )
				&& $new_id > 0
				&& $new_id === (int) ( $decoded['data']['id'] ?? 0 )
				&& 'custom-logo' === ( $attachment_filter[0]['context'] ?? null )
				&& isset( $crop_call )
				&& 7 === $crop_call['x']
				&& 11 === $crop_call['y']
				&& 320 === $crop_call['w']
				&& 210 === $crop_call['h']
				&& 160 === $crop_call['dst_w']
				&& 105 === $crop_call['dst_h'],
			'wp_ajax_crop_image normalizes context/crop integers and returns prepared attachment JS data for a new crop',
			array(
				'decoded'          => $decoded ?? null,
				'cropCall'         => $crop_call,
				'attachmentFilter' => $attachment_filter,
			)
		);

		self::collect_failure(
			$failures,
			isset( $new_meta, $new_post )
				&& is_array( $new_meta )
				&& true === ( $new_meta['component_fuzz_cropped'] ?? null )
				&& $fixture['post']['post_title'] === $new_post->post_title
				&& $fixture['post']['post_content'] === $new_post->post_content
				&& $fixture['post']['post_excerpt'] === $new_post->post_excerpt
				&& 1 === count( $created_files )
				&& 1 === count( $metadata_filters )
				&& false === \has_filter( 'wp_ajax_cropped_attachment_metadata', $metadata_filter )
				&& false === \has_filter( 'wp_ajax_cropped_attachment_id', $id_filter )
				&& false === \has_filter( 'wp_create_file_in_uploads', $file_filter ),
			'crop AJAX copies parent fields, applies file/metadata/id filters exactly once, and restores filters',
			array(
				'newMeta'         => $new_meta ?? null,
				'newPost'         => $new_post instanceof \WP_Post ? $new_post->to_array() : $new_post,
				'createdFiles'    => $created_files,
				'metadataFilters' => $metadata_filters,
				'hasFilters'      => array(
					'metadata' => \has_filter( 'wp_ajax_cropped_attachment_metadata', $metadata_filter ),
					'id'       => \has_filter( 'wp_ajax_cropped_attachment_id', $id_filter ),
					'file'     => \has_filter( 'wp_create_file_in_uploads', $file_filter ),
				),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.crop-ajax-success-filters',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function check_media_create_subsizes_ajax( \ComponentFuzz\FuzzContext $ctx, string $temp_root ): array {
		$failures      = array();
		$upload_filter = self::upload_dir_filter( $temp_root );
		$editor_filter = self::fake_editor_filter();
		$cap_filter    = self::cap_grant_filter( array( 'upload_files', 'delete_post' ) );
		$superglobals  = self::snapshot_superglobals();

		\add_filter( 'upload_dir', $upload_filter );
		\add_filter( 'wp_image_editors', $editor_filter );
		MediaImageEditRequestsFakeEditor::reset();
		try {
			$fixture = self::seed_attachment_fixture( $ctx, $temp_root, 'subsizes-ajax', array( 'withSizes' => false ) );
			MediaImageEditRequestsFakeEditor::$sizes_by_file[ $fixture['file'] ] = array(
				'width'  => $fixture['metadata']['width'],
				'height' => $fixture['metadata']['height'],
			);

			\wp_set_current_user( $ctx->int( 1, 99999 ) );
			$nonce = \wp_create_nonce( 'media-form' );

			$_POST    = array( '_ajax_nonce' => $nonce );
			$_REQUEST = $_POST;
			$missing  = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_media_create_image_subsizes();
				},
				true
			);

			\add_filter( 'map_meta_cap', $cap_filter, 10, 4 );
			$_POST    = array(
				'_ajax_nonce'    => $nonce,
				'attachment_id'  => (string) $fixture['id'],
				'_legacy_support' => '1',
			);
			$_REQUEST = $_POST;
			$legacy   = self::capture_terminating_call(
				static function (): void {
					\wp_ajax_media_create_image_subsizes();
				},
				true
			);
			\remove_filter( 'map_meta_cap', $cap_filter, 10 );

			$missing_json = json_decode( self::terminal_body( $missing ), true );
			$legacy_json  = json_decode( self::terminal_body( $legacy ), true );
		} finally {
			self::restore_superglobals( $superglobals );
			\remove_filter( 'map_meta_cap', $cap_filter, 10 );
			\remove_filter( 'wp_image_editors', $editor_filter );
			\remove_filter( 'upload_dir', $upload_filter );
			if ( isset( $fixture ) ) {
				self::delete_fixtures( array( $fixture['id'] ) );
			}
		}

		self::collect_failure(
			$failures,
			is_array( $missing_json ?? null )
				&& false === ( $missing_json['success'] ?? null )
				&& is_array( $legacy_json ?? null )
				&& true === ( $legacy_json['success'] ?? null )
				&& $fixture['id'] === (int) ( $legacy_json['data']['id'] ?? 0 )
				&& ( $missing ?? array() )['captured']
				&& ( $legacy ?? array() )['captured']
				&& ( $missing ?? array() )['filtersRestored']
				&& ( $legacy ?? array() )['filtersRestored']
				&& false === \has_filter( 'map_meta_cap', $cap_filter )
				&& false === \has_filter( 'wp_image_editors', $editor_filter ),
			'wp_ajax_media_create_image_subsizes distinguishes missing IDs from legacy successful responses and restores state',
			array(
				'missingJson' => $missing_json ?? null,
				'legacyJson'  => $legacy_json ?? null,
				'hasCap'      => \has_filter( 'map_meta_cap', $cap_filter ),
				'hasEditors'  => \has_filter( 'wp_image_editors', $editor_filter ),
			)
		);

		return self::row(
			$ctx,
			'media-image-edit-requests.media-create-subsizes-ajax',
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 4 ) )
		);
	}

	private static function seed_attachment_fixture( \ComponentFuzz\FuzzContext $ctx, string $temp_root, string $label, array $args = array() ): array {
		if ( ! isset( $GLOBALS['wp_rewrite'] ) || ! $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			$GLOBALS['wp_rewrite'] = new \WP_Rewrite();
		}

		$slug       = preg_replace( '/[^a-z0-9-]/', '-', strtolower( $label . '-' . $ctx->identifier( 4, 8 ) ) );
		$upload_dir = $temp_root . '/uploads/2026/06';
		wp_mkdir_p( $upload_dir );

		$basename = ! empty( $args['edited'] ) ? $slug . '-e1234567890123.jpg' : $slug . '.jpg';
		$file     = $upload_dir . '/' . $basename;
		file_put_contents( $file, 'component-fuzz-image-fixture-' . $slug );

		$post = array(
			'guid'           => 'http://example.test/wp-content/uploads/2026/06/' . rawurlencode( $basename ),
			'post_content'   => 'Component fuzz attachment content ' . $slug,
			'post_date'      => '2026-06-29 10:00:00',
			'post_date_gmt'  => '2026-06-29 08:00:00',
			'post_excerpt'   => 'Component fuzz caption ' . $slug,
			'post_mime_type' => 'image/jpeg',
			'post_name'      => 'cfz-' . $slug,
			'post_parent'    => 0,
			'post_status'    => 'inherit',
			'post_title'     => 'Component Fuzz Title ' . $slug,
			'post_type'      => 'attachment',
		);
		$id   = \wp_insert_post( \wp_slash( $post ), true, false );
		if ( \is_wp_error( $id ) || ! is_int( $id ) || $id <= 0 ) {
			throw new \RuntimeException( 'Could not seed image-edit attachment fixture.' );
		}

		$metadata = array(
			'file'     => '2026/06/' . $basename,
			'width'    => $ctx->int( 900, 1800 ),
			'height'   => $ctx->int( 600, 1200 ),
			'filesize' => filesize( $file ),
			'sizes'    => array(),
		);

		if ( ! empty( $args['withSizes'] ) || ! empty( $args['edited'] ) ) {
			$thumb_file = pathinfo( $basename, PATHINFO_FILENAME ) . '-150x150.jpg';
			file_put_contents( $upload_dir . '/' . $thumb_file, 'component-fuzz-thumb' );
			$metadata['sizes']['thumbnail'] = array(
				'file'      => $thumb_file,
				'width'     => 150,
				'height'    => 150,
				'mime-type' => 'image/jpeg',
				'filesize'  => filesize( $upload_dir . '/' . $thumb_file ),
			);
			$metadata['sizes']['medium'] = array(
				'file'      => pathinfo( $basename, PATHINFO_FILENAME ) . '-300x225.jpg',
				'width'     => 300,
				'height'    => 225,
				'mime-type' => 'image/jpeg',
				'filesize'  => 19,
			);
		}

		\update_post_meta( $id, '_wp_attached_file', $metadata['file'] );
		\update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		\update_post_meta( $id, '_wp_attachment_image_alt', 'Component fuzz alt ' . $slug );

		self::flush_runtime_cache();

		return array(
			'dir'      => $upload_dir,
			'file'     => $file,
			'id'       => $id,
			'metadata' => $metadata,
			'post'     => $post,
		);
	}

	private static function delete_fixtures( array $post_ids ): void {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			self::flush_runtime_cache();
			return;
		}

		$wpdb = $GLOBALS['wpdb'];
		foreach ( array_reverse( array_map( 'intval', $post_ids ) ) as $post_id ) {
			if ( method_exists( $wpdb, 'delete' ) ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
				$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
			}

			if ( function_exists( 'clean_post_cache' ) ) {
				\clean_post_cache( $post_id );
			}
		}

		self::flush_runtime_cache();
	}

	private static function fake_editor_filter(): callable {
		return static function (): array {
			return array( MediaImageEditRequestsFakeEditor::class );
		};
	}

	private static function cap_grant_filter( array $allowed_requested_caps ): callable {
		$allowed_requested_caps = array_values( array_map( 'strval', $allowed_requested_caps ) );

		return static function ( array $caps, string $cap, int $user_id, array $args ) use ( $allowed_requested_caps ): array {
			unset( $user_id );

			if ( in_array( $cap, $allowed_requested_caps, true ) ) {
				return array( 'exist' );
			}

			foreach ( $args as $arg ) {
				if ( is_string( $arg ) && in_array( $arg, $allowed_requested_caps, true ) ) {
					return array( 'exist' );
				}
			}

			foreach ( $caps as $mapped_cap ) {
				if ( in_array( (string) $mapped_cap, $allowed_requested_caps, true ) ) {
					return array( 'exist' );
				}
			}

			return $caps;
		};
	}

	private static function upload_dir_filter( string $temp_root ): callable {
		return static function () use ( $temp_root ): array {
			return array(
				'path'    => $temp_root . '/uploads/2026/06',
				'url'     => 'http://example.test/wp-content/uploads/2026/06',
				'subdir'  => '/2026/06',
				'basedir' => $temp_root . '/uploads',
				'baseurl' => 'http://example.test/wp-content/uploads',
				'error'   => false,
			);
		};
	}

	private static function capture_terminating_call( callable $callback, bool $doing_ajax ): array {
		$start_level       = ob_get_level();
		$die_calls         = array();
		$status_headers    = array();
		$captured          = false;
		$returned          = false;
		$throwable         = null;
		$output            = '';
		$cleaned_buffers   = 0;
		$doing_ajax_filter = static function () use ( $doing_ajax ): bool {
			return $doing_ajax;
		};
		$ajax_die_filter   = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'kind'    => 'ajax',
					'message' => $message,
					'title'   => $title,
					'args'    => wp_parse_args( $args ),
				);
				throw new MediaImageEditRequests_DieCaptured( 'Captured AJAX wp_die.' );
			};
		};
		$default_die_filter = static function ( $handler ) use ( &$die_calls ) {
			unset( $handler );
			return static function ( $message = '', string $title = '', $args = array() ) use ( &$die_calls ): void {
				$die_calls[] = array(
					'kind'    => 'default',
					'message' => $message,
					'title'   => $title,
					'args'    => wp_parse_args( $args ),
				);
				throw new MediaImageEditRequests_DieCaptured( 'Captured default wp_die.' );
			};
		};
		$status_filter      = static function ( string $status_header, int $code, string $description, string $protocol ) use ( &$status_headers ): string {
			$status_headers[] = array(
				'code'        => $code,
				'description' => $description,
				'header'      => $status_header,
				'protocol'    => $protocol,
			);
			return $status_header;
		};
		$charset_filter     = static function (): string {
			return 'UTF-8';
		};

		if ( ! headers_sent() ) {
			header_remove();
		}

		\add_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
		\add_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
		\add_filter( 'wp_die_handler', $default_die_filter, 1 );
		\add_filter( 'status_header', $status_filter, 10, 4 );
		\add_filter( 'pre_option_blog_charset', $charset_filter, 10, 3 );

		ob_start();
		try {
			$callback();
			$returned = true;
		} catch ( MediaImageEditRequests_DieCaptured $e ) {
			$captured = true;
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			while ( ob_get_level() > $start_level ) {
				$chunk   = ob_get_clean();
				$output  = ( false === $chunk ? '' : $chunk ) . $output;
				++$cleaned_buffers;
			}

			\remove_filter( 'wp_doing_ajax', $doing_ajax_filter, 9999 );
			\remove_filter( 'wp_die_ajax_handler', $ajax_die_filter, 1 );
			\remove_filter( 'wp_die_handler', $default_die_filter, 1 );
			\remove_filter( 'status_header', $status_filter, 10 );
			\remove_filter( 'pre_option_blog_charset', $charset_filter, 10 );

			if ( ! headers_sent() ) {
				header_remove();
			}
		}

		return array(
			'bufferBalanced'  => $start_level === ob_get_level() && 1 === $cleaned_buffers,
			'captured'        => $captured,
			'cleanedBuffers'  => $cleaned_buffers,
			'dieCalls'        => $die_calls,
			'filtersRestored' => false === \has_filter( 'wp_doing_ajax', $doing_ajax_filter )
				&& false === \has_filter( 'wp_die_ajax_handler', $ajax_die_filter )
				&& false === \has_filter( 'wp_die_handler', $default_die_filter )
				&& false === \has_filter( 'status_header', $status_filter )
				&& false === \has_filter( 'pre_option_blog_charset', $charset_filter ),
			'output'          => $output,
			'returned'        => $returned,
			'statusHeaders'   => $status_headers,
			'throwable'       => null === $throwable ? null : self::describe_throwable( $throwable ),
		);
	}

	private static function terminal_body( array $capture ): string {
		$body = (string) ( $capture['output'] ?? '' );
		foreach ( array_reverse( $capture['dieCalls'] ?? array() ) as $call ) {
			if ( isset( $call['message'] ) && '' !== (string) $call['message'] ) {
				$body .= (string) $call['message'];
				break;
			}
		}

		return $body;
	}

	private static function first_operation( $editor, string $method ): ?array {
		if ( ! $editor instanceof MediaImageEditRequestsFakeEditor ) {
			return null;
		}

		foreach ( $editor->operations as $operation ) {
			if ( $method === ( $operation['method'] ?? null ) ) {
				return $operation;
			}
		}

		return null;
	}

	private static function snapshot_state(): array {
		global $current_user, $user_ID, $wp_filter, $wp_actions, $wp_current_filter;

		$hooks = array();
		foreach ( self::HOOK_SNAPSHOT_NAMES as $hook_name ) {
			$hooks[ $hook_name ] = isset( $wp_filter[ $hook_name ] ) ? clone $wp_filter[ $hook_name ] : null;
		}

		return array(
			'superglobals'      => self::snapshot_superglobals(),
			'currentUser'       => $current_user ?? null,
			'userId'            => $user_ID ?? null,
			'actions'           => $wp_actions ?? array(),
			'currentFilter'     => $wp_current_filter ?? array(),
			'hooks'             => $hooks,
			'obLevel'           => ob_get_level(),
			'headersSent'       => headers_sent(),
			'currentUserObject' => function_exists( 'wp_get_current_user' ) ? \wp_get_current_user() : null,
			'wpRewriteHad'      => array_key_exists( 'wp_rewrite', $GLOBALS ),
			'wpRewrite'         => $GLOBALS['wp_rewrite'] ?? null,
		);
	}

	private static function restore_state( array $snapshot ): void {
		global $current_user, $user_ID, $wp_filter, $wp_actions, $wp_current_filter;

		self::restore_superglobals( $snapshot['superglobals'] );
		$current_user      = $snapshot['currentUser'];
		$user_ID           = $snapshot['userId'];
		$wp_actions        = $snapshot['actions'];
		$wp_current_filter = $snapshot['currentFilter'];

		foreach ( self::HOOK_SNAPSHOT_NAMES as $hook_name ) {
			if ( null === $snapshot['hooks'][ $hook_name ] ) {
				unset( $wp_filter[ $hook_name ] );
			} else {
				$wp_filter[ $hook_name ] = clone $snapshot['hooks'][ $hook_name ];
			}
		}

		if ( $snapshot['wpRewriteHad'] ) {
			$GLOBALS['wp_rewrite'] = $snapshot['wpRewrite'];
		} else {
			unset( $GLOBALS['wp_rewrite'] );
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}

		if ( ! headers_sent() ) {
			header_remove();
		}
	}

	private static function snapshot_superglobals(): array {
		return array(
			'GET'     => $_GET,
			'POST'    => $_POST,
			'REQUEST' => $_REQUEST,
			'SERVER'  => $_SERVER,
		);
	}

	private static function restore_superglobals( array $snapshot ): void {
		$_GET     = $snapshot['GET'];
		$_POST    = $snapshot['POST'];
		$_REQUEST = $snapshot['REQUEST'];
		$_SERVER  = $snapshot['SERVER'];
	}

	private static function state_restored( array $snapshot ): bool {
		global $wp_filter, $wp_current_filter;

		if ( $_GET !== $snapshot['superglobals']['GET'] || $_POST !== $snapshot['superglobals']['POST'] || $_REQUEST !== $snapshot['superglobals']['REQUEST'] ) {
			return false;
		}

		if ( ( $wp_current_filter ?? array() ) !== $snapshot['currentFilter'] ) {
			return false;
		}

		foreach ( self::HOOK_SNAPSHOT_NAMES as $hook_name ) {
			$had_hook = null !== $snapshot['hooks'][ $hook_name ];
			if ( $had_hook !== isset( $wp_filter[ $hook_name ] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function make_temp_root( \ComponentFuzz\FuzzContext $ctx ): ?string {
		$base = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-image-edit-' . getmypid() . '-' . $ctx->seed() . '-' . $ctx->iteration();
		self::remove_dir_recursive( $base );

		return wp_mkdir_p( $base . '/uploads/2026/06' ) ? $base : null;
	}

	private static function remove_dir_recursive( string $dir ): void {
		$temp_prefix = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-media-image-edit-';
		if ( ! str_starts_with( $dir, $temp_prefix ) || ! file_exists( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $dir );
	}

	private static function flush_runtime_cache(): void {
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

	private static function row(
		\ComponentFuzz\FuzzContext $ctx,
		string $invariant,
		bool $ok,
		array $details = array()
	): array {
		return array(
			'kind'      => 'component-fuzz-result',
			'surface'   => self::NAME,
			'invariant' => $invariant,
			'ok'        => $ok,
			'status'    => $ok ? 'passed' : 'failed',
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'details'   => $details,
		);
	}

	private static function describe_throwable( \Throwable $e ): array {
		return array(
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}

final class MediaImageEditRequests_DieCaptured extends \RuntimeException {
}
