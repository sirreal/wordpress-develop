<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes importer registry helpers, import upload UI, text diffs, and error export.
 */
final class ImportDiffSurface {
	public const NAME = 'import-diff';

	private const PREVIEW_BYTES = 180;

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'import-diff.bootstrap-apis-available',
					'Required import and diff APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$rows     = array();

		try {
			self::reset_runtime();

			$rows[] = self::check_importer_registry( $ctx->fork( 'registry' ) );
			$rows[] = self::check_import_upload_form( $ctx->fork( 'upload-form' ) );
			$rows[] = self::check_text_diff_rendering( $ctx->fork( 'text-diff' ) );
			$rows[] = self::check_error_export_and_merge( $ctx->fork( 'error-export' ) );
			$rows[] = self::check_imported_comment_lookup( $ctx->fork( 'comment-lookup' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'import-diff.surface-no-throw',
				array( 'throwable' => self::describe_throwable( $e ) )
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'import-diff.global-state-restored',
			self::state_matches( $snapshot ),
			array( 'trackedGlobals' => array_keys( $snapshot['globals'] ) )
		);

		return $rows;
	}

	public static function importer_callback(): void {}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'Text_Diff', 'WP_Error', 'WP_Importer', 'WP_Text_Diff_Renderer_Table' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'add_filter',
				'get_importers',
				'remove_filter',
				'register_importer',
				'wp_import_upload_form',
				'wp_text_diff',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_importer_registry( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wp_importers;

		$failures     = array();
		$wp_importers = array();
		$callback     = array( __CLASS__, 'importer_callback' );
		$registered   = array(
			'component-fuzz-beta-10' => array(
				'Beta 10 ' . $ctx->identifier( 3, 6 ),
				'Imports beta-ten data & checks sorting.',
				$callback,
			),
			'component-fuzz-alpha-2' => array(
				'alpha 2 ' . $ctx->identifier( 3, 6 ),
				'Imports alpha-two data.',
				$callback,
			),
			'component-fuzz-alpha-10' => array(
				'Alpha 10 ' . $ctx->identifier( 3, 6 ),
				'Imports alpha-ten data.',
				$callback,
			),
			'component-fuzz-zeta' => array(
				'Zeta ' . $ctx->identifier( 3, 6 ),
				'Imports zeta data.',
				$callback,
			),
		);

		foreach ( $registered as $id => $entry ) {
			$result = \register_importer( $id, $entry[0], $entry[1], $entry[2] );
			self::collect_failure(
				$failures,
				null === $result,
				'register_importer returns void for valid callbacks',
				array( 'id' => $id, 'result' => self::describe_value( $result ) )
			);
		}

		$error      = new \WP_Error( 'component_fuzz_importer_unavailable', 'Importer unavailable.' );
		$error_out  = \register_importer( 'component-fuzz-error', 'Error', 'Should not register.', $error );
		$importers  = \get_importers();
		$expected   = $registered;
		$sort_names = $expected;
		uasort( $sort_names, '_usort_by_first_member' );

		self::collect_failure(
			$failures,
			$error === $error_out
				&& ! isset( $importers['component-fuzz-error'] )
				&& array_keys( $sort_names ) === array_keys( $importers )
				&& self::importer_entries_match( $expected, $importers ),
			'importer registry stores valid importers, preserves callbacks, sorts by name, and rejects WP_Error callbacks',
			array(
				'expectedOrder' => array_keys( $sort_names ),
				'actualOrder'   => array_keys( is_array( $importers ) ? $importers : array() ),
				'errorOut'      => self::describe_value( $error_out ),
				'importers'     => self::describe_value( $importers ),
			)
		);

		return self::result( $ctx, 'import-diff.importer-registry.sorting-and-error-callbacks', $failures );
	}

	private static function check_import_upload_form( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures       = array();
		$limit          = 1024 * $ctx->int( 2, 256 );
		$limit_observed = array();
		$limit_filter   = static function ( int $bytes ) use ( $limit, &$limit_observed ): int {
			$limit_observed[] = $bytes;
			return $limit;
		};

		$action = 'admin.php?import=component-fuzz&step=' . rawurlencode( $ctx->identifier( 3, 10 ) ) . '&bad=<script>alert(1)</script>';

		\add_filter( 'import_upload_size_limit', $limit_filter );
		try {
			ob_start();
			\wp_import_upload_form( $action );
			$output = ob_get_clean();
		} finally {
			\remove_filter( 'import_upload_size_limit', $limit_filter );
			if ( ob_get_level() > 0 && false === isset( $output ) ) {
				ob_end_clean();
			}
		}

		self::collect_failure(
			$failures,
			isset( $output )
				&& '' !== $output
				&& array() !== $limit_observed
				&& str_contains( $output, 'id="import-upload-form"' )
				&& str_contains( $output, 'enctype="multipart/form-data"' )
				&& str_contains( $output, 'name="import"' )
				&& str_contains( $output, 'name="action" value="save"' )
				&& str_contains( $output, '_wpnonce=' )
				&& ! str_contains( $output, '<script>' )
				&& ! str_contains( $output, '</script>' ),
			'import upload form applies size filters, emits expected controls, and escapes action data',
			array(
				'action'        => self::preview( $action ),
				'limit'         => $limit,
				'limitObserved' => $limit_observed,
				'output'        => self::preview( $output ?? '' ),
			)
		);

		return self::result( $ctx, 'import-diff.import-upload-form.controls-size-and-escaping', $failures );
	}

	private static function check_text_diff_rendering( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$token    = $ctx->identifier( 3, 10 );
		$left     = "common <tag>& {$token}\nremoved {$token}\nkept";
		$right    = "common <tag>& {$token}\nadded & changed {$token}\nkept";
		$split    = \wp_text_diff(
			$left,
			$right,
			array(
				'title'           => 'Component Fuzz Diff',
				'title_left'      => 'Before',
				'title_right'     => 'After',
				'show_split_view' => true,
			)
		);
		$unified  = \wp_text_diff(
			$left,
			$right,
			array(
				'title_left'      => 'Before',
				'title_right'     => 'After',
				'show_split_view' => false,
			)
		);
		$same     = \wp_text_diff( "same\r\nwhitespace\tcase", "same\nwhitespace case" );

		self::collect_failure(
			$failures,
			is_string( $split )
				&& str_contains( $split, "<table class='diff is-split-view'>" )
				&& str_contains( $split, "class='diff-deletedline'" )
				&& str_contains( $split, "class='diff-addedline'" )
				&& str_contains( $split, 'common &lt;tag&gt;&amp; ' . $token )
				&& str_contains( $split, 'added &amp; changed' )
				&& str_contains( $split, $token )
				&& ! str_contains( $split, 'common <tag>& ' . $token ),
			'wp_text_diff split view escapes changed content and marks added/deleted lines',
			array( 'split' => self::preview( $split ) )
		);
		self::collect_failure(
			$failures,
			is_string( $unified )
				&& str_contains( $unified, "<table class='diff'>" )
				&& ! str_contains( $unified, 'is-split-view' )
				&& str_contains( $unified, 'Before' )
				&& ! str_contains( $unified, '<th>After</th>' ),
			'wp_text_diff unified view omits split class and right-side header',
			array( 'unified' => self::preview( $unified ) )
		);
		self::collect_failure(
			$failures,
			'' === $same,
			'wp_text_diff returns an empty string after normalizing equivalent whitespace',
			array( 'same' => self::preview( $same ) )
		);

		return self::result( $ctx, 'import-diff.text-diff.rendering-and-normalization', $failures );
	}

	private static function check_error_export_and_merge( \ComponentFuzz\FuzzContext $ctx ): array {
		$failures = array();
		$source   = new \WP_Error();
		$target   = new \WP_Error( 'existing', 'Existing target message.', array( 'kept' => true ) );
		$merged   = new \WP_Error( 'merged', 'Existing merge message.' );
		$code     = 'component_fuzz_' . $ctx->identifier( 4, 10 );
		$message  = 'Message <' . $ctx->identifier( 3, 7 ) . '>';
		$data_one = array(
			'seed'  => $ctx->seed(),
			'token' => $ctx->identifier( 4, 10 ),
		);
		$data_two = array(
			'next' => $ctx->identifier( 4, 10 ),
		);

		$source->add( $code, $message );
		$source->add_data( $data_one, $code );
		$source->add_data( $data_two, $code );
		$source->export_to( $target );
		$merged->merge_from( $source );

		self::collect_failure(
			$failures,
			in_array( 'existing', $target->get_error_codes(), true )
				&& in_array( $code, $target->get_error_codes(), true )
				&& array( $message ) === $target->get_error_messages( $code )
				&& array( $data_one, $data_two ) === $target->get_all_error_data( $code )
				&& array( $message ) === $merged->get_error_messages( $code )
				&& array( $data_one, $data_two ) === $merged->get_all_error_data( $code ),
			'WP_Error export_to and merge_from copy messages and all error data without dropping existing target errors',
			array(
				'code'   => $code,
				'target' => self::describe_error( $target ),
				'merged' => self::describe_error( $merged ),
			)
		);

		$target->remove( $code );
		self::collect_failure(
			$failures,
			! in_array( $code, $target->get_error_codes(), true )
				&& array() === $target->get_all_error_data( $code )
				&& in_array( 'existing', $target->get_error_codes(), true ),
			'WP_Error::remove deletes copied messages and data for one code only',
			array( 'target' => self::describe_error( $target ) )
		);

		return self::result( $ctx, 'import-diff.wp-error.export-merge-and-remove', $failures );
	}

	private static function check_imported_comment_lookup( \ComponentFuzz\FuzzContext $ctx ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'component_fuzz_content_counts' ) ) {
			return $ctx->skip(
				'import-diff.importer.imported-comment-lookup',
				'The in-memory wpdb content stub is unavailable.'
			);
		}

		$before = $wpdb->component_fuzz_content_counts();
		if ( array_sum( $before ) !== 0 ) {
			return $ctx->skip(
				'import-diff.importer.imported-comment-lookup',
				'The content stub was not empty before the importer lookup case.',
				array( 'counts' => $before )
			);
		}

		$failures = array();
		$blog_id  = $ctx->int( 2, 20 );
		$source_a = $ctx->int( 100, 999 );
		$source_b = $source_a + $ctx->int( 1, 20 );
		$other    = $blog_id + 100;
		$ids      = array();

		try {
			$ids[] = self::insert_comment_agent( $blog_id . '-' . $source_a, 'first' );
			$ids[] = self::insert_comment_agent( $blog_id . '-' . $source_b, 'second' );
			$ids[] = self::insert_comment_agent( $other . '-' . $source_a, 'other-blog' );

			$importer = new \WP_Importer();
			$lookup   = $importer->get_imported_comments( (string) $blog_id );

			self::collect_failure(
				$failures,
				array(
					$source_a => $ids[0],
					$source_b => $ids[1],
				) === $lookup,
				'WP_Importer maps source comment IDs to local comment IDs for the selected blog only',
				array(
					'blogId' => $blog_id,
					'ids'    => $ids,
					'lookup' => $lookup,
				)
			);
		} finally {
			$wpdb->component_fuzz_reset_content();
		}

		return self::result( $ctx, 'import-diff.importer.imported-comment-lookup', $failures );
	}

	private static function insert_comment_agent( string $comment_agent, string $label ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->comments,
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => 'Component Fuzz ' . $label,
				'comment_author_email' => 'comment-' . $label . '@example.test',
				'comment_author_url'   => '',
				'comment_author_IP'    => '192.0.2.55',
				'comment_date'         => '2026-06-23 00:00:00',
				'comment_date_gmt'     => '2026-06-23 00:00:00',
				'comment_content'      => 'Imported comment ' . $label,
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => $comment_agent,
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			)
		);

		return (int) $wpdb->insert_id;
	}

	private static function importer_entries_match( array $expected, $actual ): bool {
		if ( ! is_array( $actual ) ) {
			return false;
		}

		foreach ( $expected as $id => $entry ) {
			if ( ! isset( $actual[ $id ] ) || $entry !== $actual[ $id ] ) {
				return false;
			}
		}

		return true;
	}

	private static function reset_runtime(): void {
		$GLOBALS['wp_importers'] = array();
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_importers',
				'wp_object_cache',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? self::clone_value( $GLOBALS[ $name ] ) : null,
			);
		}

		return array(
			'globals' => $globals,
		);
	}

	private static function restore_state( array $snapshot ): void {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] ) {
				$GLOBALS[ $name ] = $entry['value'];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'component_fuzz_reset_content' ) ) {
			$GLOBALS['wpdb']->component_fuzz_reset_content();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
		}

		return true;
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			array( 'failures' => array_slice( $failures, 0, 8 ) )
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $details = array() ): void {
		if ( ! $ok ) {
			$failures[] = array(
				'message' => $message,
				'details' => $details,
			);
		}
	}

	private static function describe_error( \WP_Error $error ): array {
		$out = array();
		foreach ( $error->get_error_codes() as $code ) {
			$out[ $code ] = array(
				'messages' => $error->get_error_messages( $code ),
				'data'     => $error->get_all_error_data( $code ),
			);
		}
		return $out;
	}

	private static function describe_value( $value ) {
		if ( $value instanceof \WP_Error ) {
			return self::describe_error( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::describe_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		return $value;
	}

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
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
