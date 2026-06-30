<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WXR export generation in a subprocess to avoid helper redeclarations.
 */
final class WxrExportSurface {
	public const NAME = 'wxr-export';

	private const PREVIEW_BYTES = 220;
	private const WXR_NS        = 'http://wordpress.org/export/1.2/';
	private const CONTENT_NS    = 'http://purl.org/rss/1.0/modules/content/';
	private const EXCERPT_NS    = 'http://wordpress.org/export/1.2/excerpt/';
	private const INVALID_MARK  = '__COMPONENT_FUZZ_INVALID_UTF8__';
	private const INVALID_BYTES = "\xC3\x28";

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'wxr-export.bootstrap-apis-available',
					'Required WXR export fuzzing APIs are unavailable.',
					array( 'missing' => implode( ', ', $missing ) )
				),
			);
		}

		$snapshot = self::snapshot_state();
		$case     = self::prepare_case( $ctx );
		$rows     = array();

		try {
			$run = self::run_child_export( $case );

			$rows[] = self::check_child_process( $ctx->fork( 'child' ), $case, $run );
			if ( $run['ok'] ) {
				$xml     = base64_decode( (string) $run['result']['xmlBase64'], true );
				$xml     = false === $xml ? '' : $xml;
				$parsed  = self::parse_xml( $xml );
				$details = self::extract_wxr_details( $parsed['xml'] );

				$rows[] = self::check_xml_safety( $ctx->fork( 'xml' ), $case, $run, $xml, $parsed );
				$rows[] = self::check_selection_filters( $ctx->fork( 'selection' ), $case, $details );
				$rows[] = self::check_export_content_filters( $ctx->fork( 'content-filters' ), $case, $run, $details );
				$rows[] = self::check_meta_filters( $ctx->fork( 'meta' ), $case, $run, $details, $xml );
				$rows[] = self::check_attachment_urls_and_filemeta( $ctx->fork( 'attachments' ), $case, $details );
				$rows[] = self::check_authors_and_terms( $ctx->fork( 'authors-terms' ), $case, $details );
				$rows[] = self::check_header_capture( $ctx->fork( 'headers' ), $case, $run );
				$rows[] = self::check_child_state_restored( $ctx->fork( 'child-state' ), $case, $run );
			}
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'wxr-export.surface-no-throw',
				array(
					'case'      => self::case_summary( $case ),
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_state( $snapshot );
		}

		$rows[] = $ctx->result(
			'wxr-export.parent-state-restored',
			self::state_matches( $snapshot ),
			array(
				'trackedGlobals' => array_keys( $snapshot['globals'] ),
				'obLevel'        => ob_get_level(),
			)
		);

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach ( array( 'SimpleXMLElement' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				$missing[] = "class {$class}";
			}
		}

		foreach (
			array(
				'base64_decode',
				'base64_encode',
				'json_decode',
				'json_encode',
				'libxml_clear_errors',
				'libxml_get_errors',
				'libxml_use_internal_errors',
				'proc_close',
				'proc_open',
				'random_bytes',
				'simplexml_load_string',
				'stream_get_contents',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		if ( ! defined( 'PHP_BINARY' ) || '' === PHP_BINARY ) {
			$missing[] = 'PHP_BINARY';
		}

		return $missing;
	}

	private static function run_child_export( array $case ): array {
		$dir = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'component-fuzz-wxr-export';
		\ComponentFuzz\ensure_dir( $dir );

		$script = $dir . DIRECTORY_SEPARATOR . 'child-' . getmypid() . '-' . bin2hex( random_bytes( 6 ) ) . '.php';
		file_put_contents( $script, self::child_program() );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Isolates export_wp() helper definitions in a local PHP subprocess.
		$process = proc_open( array( PHP_BINARY, $script ), $descriptors, $pipes, \ComponentFuzz\repo_root() );
		if ( ! is_resource( $process ) ) {
			@unlink( $script );
			return array(
				'ok'       => false,
				'exitCode' => -1,
				'stdout'   => '',
				'stderr'   => 'proc_open failed',
				'result'   => null,
			);
		}

		$payload = json_encode( $case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		fwrite( $pipes[0], false === $payload ? '{}' : $payload );
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		@unlink( $script );

		$result = json_decode( (string) $stdout, true );
		return array(
			'ok'       => 0 === $exit_code && is_array( $result ) && ! empty( $result['ok'] ),
			'exitCode' => $exit_code,
			'stdout'   => (string) $stdout,
			'stderr'   => (string) $stderr,
			'result'   => is_array( $result ) ? $result : null,
		);
	}

	private static function check_child_process( \ComponentFuzz\FuzzContext $ctx, array $case, array $run ): array {
		$failures = array();
		self::collect_failure(
			$failures,
			$run['ok'],
			'isolated export_wp subprocess exits cleanly and returns structured JSON',
			array(
				'exitCode' => $run['exitCode'],
				'stdout'   => self::preview( $run['stdout'] ),
				'stderr'   => self::preview( $run['stderr'] ),
				'case'     => self::case_summary( $case ),
			)
		);

		if ( is_array( $run['result'] ) ) {
			self::collect_failure(
				$failures,
				1 === (int) ( $run['result']['exportCalls'] ?? 0 ),
				'the child calls export_wp exactly once to avoid wxr_* redeclarations',
				array( 'exportCalls' => $run['result']['exportCalls'] ?? null )
			);
		}

		return self::result( $ctx, 'wxr-export.subprocess-isolated-export', $failures );
	}

	private static function check_xml_safety( \ComponentFuzz\FuzzContext $ctx, array $case, array $run, string $xml, array $parsed ): array {
		$failures = array();

		self::collect_failure(
			$failures,
			$parsed['ok'] && str_starts_with( $xml, '<?xml version="1.0" encoding="UTF-8" ?>' ),
			'captured WXR output is parseable XML with the expected declaration',
			array(
				'xmlErrors' => $parsed['errors'],
				'preview'   => self::preview( $xml ),
				'case'      => self::case_summary( $case ),
			)
		);
		self::collect_failure(
			$failures,
			1 === preg_match( '//u', $xml ) && ! str_contains( $xml, self::INVALID_BYTES ),
			'invalid source bytes are converted before XML output',
			array( 'containsInvalidBytes' => str_contains( $xml, self::INVALID_BYTES ) )
		);
		self::collect_failure(
			$failures,
			str_contains( $xml, ']]]]><![CDATA[>' ),
			'CDATA terminators are split safely inside WXR CDATA sections',
			array( 'hasSplitTerminator' => str_contains( $xml, ']]]]><![CDATA[>' ) )
		);
		self::collect_failure(
			$failures,
			is_array( $run['result'] )
				&& ! empty( $run['result']['containsInvalidInput'] )
				&& ! empty( $run['result']['containsCdataTerminatorInput'] ),
			'the generated fixture exercised invalid UTF-8 and CDATA terminator inputs',
			array(
				'containsInvalidInput'          => $run['result']['containsInvalidInput'] ?? null,
				'containsCdataTerminatorInput' => $run['result']['containsCdataTerminatorInput'] ?? null,
			)
		);

		return self::result( $ctx, 'wxr-export.xml.well-formed-cdata-and-utf8', $failures );
	}

	private static function check_selection_filters( \ComponentFuzz\FuzzContext $ctx, array $case, array $details ): array {
		$failures = array();
		$expected = self::expected_exported_post_ids( $case );

		self::collect_failure(
			$failures,
			$expected === $details['postIds'],
			'content, status, author, category, and date arguments select the expected post IDs',
			array(
				'scenario' => $case['scenario'],
				'args'     => $case['args'],
				'expected' => $expected,
				'actual'   => $details['postIds'],
			)
		);
		self::collect_failure(
			$failures,
			! in_array( $case['autoDraftPostId'], $details['postIds'], true ),
			'auto-draft posts are excluded from default and all-content exports',
			array(
				'autoDraftPostId' => $case['autoDraftPostId'],
				'actual'          => $details['postIds'],
			)
		);
		self::collect_failure(
			$failures,
			count( $expected ) === count( $details['statuses'] ),
			'each exported item carries a WXR post status',
			array( 'statuses' => $details['statuses'] )
		);

		return self::result( $ctx, 'wxr-export.selection-arguments-and-auto-draft', $failures );
	}

	private static function check_export_content_filters( \ComponentFuzz\FuzzContext $ctx, array $case, array $run, array $details ): array {
		$failures = array();
		$events   = is_array( $run['result'] ) ? ( $run['result']['filterEvents'] ?? array() ) : array();
		$markers  = $case['exportFilterMarkers'];
		$count    = count( $details['postIds'] );

		self::collect_failure(
			$failures,
			$count === count( $events['title'] ?? array() )
				&& $count === count( $events['content'] ?? array() )
				&& $count === count( $events['excerpt'] ?? array() ),
			'title, content, and excerpt export filters run once for each emitted item',
			array(
				'postIds' => $details['postIds'],
				'events'  => array(
					'title'   => count( $events['title'] ?? array() ),
					'content' => count( $events['content'] ?? array() ),
					'excerpt' => count( $events['excerpt'] ?? array() ),
				),
			)
		);

		$mismatches = array();
		foreach ( $details['postIds'] as $post_id ) {
			$post = $case['posts'][ $post_id ] ?? null;
			if ( ! is_array( $post ) ) {
				$mismatches[] = array( 'postId' => $post_id, 'reason' => 'missing fixture post' );
				continue;
			}

			$title   = $details['titles'][ $post_id ] ?? null;
			$content = $details['contents'][ $post_id ] ?? null;
			$excerpt = $details['excerpts'][ $post_id ] ?? null;

			if (
				$markers['titlePrefix'] . $post['post_title'] !== $title
				|| ! is_string( $content )
				|| ! str_ends_with( $content, $markers['contentSuffix'] )
				|| $post['post_excerpt'] . $markers['excerptSuffix'] !== $excerpt
			) {
				$mismatches[] = array(
					'postId'  => $post_id,
					'title'   => $title,
					'content' => is_string( $content ) ? self::preview( $content ) : $content,
					'excerpt' => $excerpt,
				);
			}
		}

		self::collect_failure(
			$failures,
			array() === $mismatches,
			'filtered titles, contents, and excerpts are emitted in the parsed WXR item payloads',
			array(
				'markers'    => $markers,
				'mismatches' => array_slice( $mismatches, 0, 8 ),
			)
		);

		return self::result( $ctx, 'wxr-export.item-content-export-filters', $failures );
	}

	private static function check_meta_filters( \ComponentFuzz\FuzzContext $ctx, array $case, array $run, array $details, string $xml ): array {
		$failures = array();
		$events   = is_array( $run['result'] ) ? ( $run['result']['filterEvents'] ?? array() ) : array();
		$expected_events = self::expected_meta_filter_events( $case, $details['postIds'] );

		self::collect_failure(
			$failures,
			! in_array( '_edit_lock', $details['postMetaKeys'], true )
				&& ! in_array( $case['skipKeys']['post'], $details['postMetaKeys'], true )
				&& ! in_array( $case['skipKeys']['term'], $details['termMetaKeys'], true )
				&& ! in_array( $case['skipKeys']['comment'], $details['commentMetaKeys'], true ),
			'post, term, and comment meta skip filters suppress the expected keys',
			array(
				'postMetaKeys'    => $details['postMetaKeys'],
				'termMetaKeys'    => $details['termMetaKeys'],
				'commentMetaKeys' => $details['commentMetaKeys'],
				'skipKeys'        => $case['skipKeys'],
			)
		);
		self::collect_failure(
			$failures,
			! in_array( 101, $details['postIds'], true )
				|| (
					in_array( $case['keepKeys']['post'], $details['postMetaKeys'], true )
					&& in_array( $case['keepKeys']['comment'], $details['commentMetaKeys'], true )
				),
			'kept post and comment meta remain exported when their owner is selected',
			array(
				'postIds'         => $details['postIds'],
				'postMetaKeys'    => $details['postMetaKeys'],
				'commentMetaKeys' => $details['commentMetaKeys'],
			)
		);
		self::collect_failure(
			$failures,
			! str_contains( $xml, '_edit_lock' )
				&& ! str_contains( $xml, $case['skipKeys']['post'] )
				&& ! str_contains( $xml, $case['skipKeys']['term'] )
				&& ! str_contains( $xml, $case['skipKeys']['comment'] ),
			'skipped meta keys do not appear in raw WXR output',
			array( 'preview' => self::preview( $xml ) )
		);
		self::collect_failure(
			$failures,
			self::expected_events_seen( $expected_events, $events ),
			'WXR meta skip filters are invoked for generated meta reachable by this export mode',
			array(
				'expectedEvents' => $expected_events,
				'events'         => $events,
			)
		);

		return self::result( $ctx, 'wxr-export.meta-skip-filters', $failures );
	}

	private static function check_attachment_urls_and_filemeta( \ComponentFuzz\FuzzContext $ctx, array $case, array $details ): array {
		$failures              = array();
		$expected_attachments  = self::expected_attachment_details( $case, $details['postIds'] );
		$actual_attachment_ids = array_keys( $details['attachmentUrls'] );
		sort( $actual_attachment_ids, SORT_NUMERIC );

		self::collect_failure(
			$failures,
			array_keys( $expected_attachments ) === $actual_attachment_ids,
			'only exported attachment items carry WXR attachment URLs',
			array(
				'expectedAttachmentIds' => array_keys( $expected_attachments ),
				'actualAttachmentIds'   => $actual_attachment_ids,
				'postTypes'             => $details['postTypes'],
			)
		);

		$mismatches = array();
		foreach ( $expected_attachments as $post_id => $expected ) {
			$file_values = $details['postMetaValues'][ $post_id ]['_wp_attached_file'] ?? array();
			$actual_url  = $details['attachmentUrls'][ $post_id ] ?? null;

			if ( $expected['url'] !== $actual_url || ! in_array( $expected['file'], $file_values, true ) ) {
				$mismatches[] = array(
					'postId'             => $post_id,
					'expectedUrl'        => $expected['url'],
					'actualUrl'          => $actual_url,
					'expectedFileMeta'   => $expected['file'],
					'actualFileMetaList' => $file_values,
				);
			}
		}

		self::collect_failure(
			$failures,
			array() === $mismatches,
			'attachment URLs are derived from _wp_attached_file and the file meta is serialized with each attachment item',
			array(
				'uploadsBaseUrl' => $case['uploadsBaseUrl'],
				'mismatches'     => array_slice( $mismatches, 0, 8 ),
			)
		);

		return self::result( $ctx, 'wxr-export.attachment-url-and-filemeta', $failures );
	}

	private static function expected_meta_filter_events( array $case, array $post_ids ): array {
		$expected = array(
			'postmeta'    => array(),
			'termmeta'    => array(),
			'commentmeta' => array(),
		);

		if ( in_array( 101, $post_ids, true ) ) {
			$expected['postmeta'][]    = $case['skipKeys']['post'];
			$expected['commentmeta'][] = $case['skipKeys']['comment'];
		}

		if ( in_array( $case['args']['content'], array( 'all', 'post' ), true ) && ( 'all' === $case['args']['content'] || $case['args']['category'] ) ) {
			$expected['termmeta'][] = $case['skipKeys']['term'];
		}

		return $expected;
	}

	private static function expected_events_seen( array $expected, array $events ): bool {
		foreach ( $expected as $bucket => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! in_array( $key, $events[ $bucket ] ?? array(), true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function check_authors_and_terms( \ComponentFuzz\FuzzContext $ctx, array $case, array $details ): array {
		$failures         = array();
		$expected_authors = self::expected_author_ids( $case, $details['postIds'] );

		self::collect_failure(
			$failures,
			$expected_authors === $details['authorIds']
				&& count( $details['authorIds'] ) === count( array_unique( $details['authorIds'] ) ),
			'authors are unique and associated with exported post IDs',
			array(
				'expectedAuthors' => $expected_authors,
				'actualAuthors'   => $details['authorIds'],
				'postIds'         => $details['postIds'],
			)
		);

		if ( 'all' === $case['args']['content'] ) {
			self::collect_failure(
				$failures,
				self::appears_before( $details['categorySlugs'], $case['terms']['categoryParent']['slug'], $case['terms']['categoryChild']['slug'] )
					&& self::appears_before( $details['termSlugs'], $case['terms']['customParent']['slug'], $case['terms']['customChild']['slug'] ),
				'parent categories and custom terms precede their children in full exports',
				array(
					'categorySlugs' => $details['categorySlugs'],
					'termSlugs'     => $details['termSlugs'],
				)
			);
		} else {
			self::collect_failure(
				$failures,
				true,
				'term hierarchy order is only emitted by full-content exports',
				array( 'scenario' => $case['scenario'] )
			);
		}

		return self::result( $ctx, 'wxr-export.authors-and-term-ordering', $failures );
	}

	private static function check_header_capture( \ComponentFuzz\FuzzContext $ctx, array $case, array $run ): array {
		$result               = is_array( $run['result'] ) ? $run['result'] : array();
		$headers              = $result['headers'] ?? array();
		$headers_observable   = ! empty( $result['headersObservable'] );
		$filename_filter      = $result['filenameFilter'] ?? null;
		$filename_filter_count = is_array( $result['filterEvents']['filename'] ?? null ) ? count( $result['filterEvents']['filename'] ) : 0;
		$source_facts         = self::export_header_source_facts();
		$expected_disposition = (string) ( $result['expectedContentDisposition'] ?? ( 'Content-Disposition: attachment; filename=' . $case['expectedFilename'] ) );
		$expected_type        = (string) ( $result['expectedContentType'] ?? 'Content-Type: text/xml; charset=UTF-8' );
		$failures             = array();

		if ( $headers_observable ) {
			$joined = implode( "\n", array_map( 'strval', $headers ) );
			self::collect_failure(
				$failures,
				str_contains( $joined, $expected_disposition )
					&& str_contains( $joined, $expected_type ),
				'observable CLI headers include the filtered filename and XML content type',
				array(
					'headers'             => $headers,
					'expectedDisposition' => $expected_disposition,
					'expectedType'        => $expected_type,
				)
			);
		} else {
			self::collect_failure(
				$failures,
				is_array( $filename_filter )
					&& 1 === $filename_filter_count
					&& '' !== (string) ( $filename_filter['filename'] ?? '' )
					&& '' !== (string) ( $filename_filter['sitename'] ?? '' )
					&& preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $filename_filter['date'] ?? '' ) )
					&& (string) ( $filename_filter['filename'] ?? '' ) === (string) ( $filename_filter['sitename'] ?? '' ) . 'WordPress.' . (string) ( $filename_filter['date'] ?? '' ) . '.xml'
					&& $case['expectedFilename'] === ( $filename_filter['filteredFilename'] ?? null )
					&& 'UTF-8' === ( $result['blogCharset'] ?? null )
					&& 'Content-Disposition: attachment; filename=' . $case['expectedFilename'] === $expected_disposition
					&& 'Content-Type: text/xml; charset=UTF-8' === $expected_type,
				'unobservable CLI headers still account for export filename filtering and XML content-type intent',
				array(
					'filenameFilter'      => $filename_filter,
					'filenameFilterCount' => $filename_filter_count,
					'expectedDisposition' => $expected_disposition,
					'expectedType'        => $expected_type,
					'headersObservable'   => $headers_observable,
					'sapi'                => $result['sapi'] ?? PHP_SAPI,
				)
			);

			self::collect_failure(
				$failures,
				true === ( $source_facts['ordered'] ?? null ),
				'export_wp() source applies filename filter before content-disposition and content-type headers',
				$source_facts
			);
		}

		return self::result(
			$ctx,
			'wxr-export.headers.filename-and-content-type',
			$failures,
			array(
				'headersObservable'   => $headers_observable,
				'headers'             => $headers,
				'filenameFilter'      => $filename_filter,
				'filenameFilterCount' => $filename_filter_count,
				'expectedDisposition' => $expected_disposition,
				'expectedType'        => $expected_type,
				'mode'                => $headers_observable ? 'observable-headers' : 'unobservable-source-fallback',
				'exportHeaderSource'  => $source_facts,
				'sapi'                => $result['sapi'] ?? PHP_SAPI,
			)
		);
	}

	private static function check_child_state_restored( \ComponentFuzz\FuzzContext $ctx, array $case, array $run ): array {
		$result   = is_array( $run['result'] ) ? $run['result'] : array();
		$failures = array();

		self::collect_failure(
			$failures,
			! empty( $result['stateRestored'] ) && ! empty( $result['outputBufferRestored'] ),
			'child process restores globals, filters, wpdb, and output-buffer depth after export',
			array(
				'stateRestored'        => $result['stateRestored'] ?? null,
				'outputBufferRestored' => $result['outputBufferRestored'] ?? null,
				'case'                 => self::case_summary( $case ),
			)
		);

		return self::result( $ctx, 'wxr-export.child-state-restored', $failures );
	}

	private static function prepare_case( \ComponentFuzz\FuzzContext $ctx ): array {
		$token      = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $ctx->identifier( 5, 10 ) ) );
		$token      = trim( $token, '-' );
		$token      = '' === $token ? 'wxr' : $token;
		$scenario   = self::scenario_for_iteration( $ctx->iteration() );
		$custom_tax = 'component_fuzz_tax_' . substr( $token, 0, 8 );
		$custom_pt  = 'component_fuzz_export';

		$terms = array(
			'categoryChild'  => self::term_row( 11, 101, 'category', 'fuzz-child-' . $token, 'Child ' . $token, 10 ),
			'categoryParent' => self::term_row( 10, 100, 'category', 'fuzz-parent-' . $token, 'Parent ' . $token, 0 ),
			'tag'            => self::term_row( 20, 200, 'post_tag', 'fuzz-tag-' . $token, 'Tag ' . $token, 0 ),
			'customChild'    => self::term_row( 31, 301, $custom_tax, 'fuzz-custom-child-' . $token, 'Custom Child ' . $token, 30 ),
			'customParent'   => self::term_row( 30, 300, $custom_tax, 'fuzz-custom-parent-' . $token, 'Custom Parent ' . $token, 0 ),
			'navMenu'        => self::term_row( 40, 400, 'nav_menu', 'fuzz-menu-' . $token, 'Menu ' . $token, 0 ),
		);

		$users = array(
			1 => self::user_row( 1, 'alice-' . $token, 'alice-' . $token . '@example.test', 'Alice ' . $token ),
			2 => self::user_row( 2, 'bravo-' . $token, 'bravo-' . $token . '@example.test', 'Bravo ' . $token ),
			3 => self::user_row( 3, 'charlie-' . $token, 'charlie-' . $token . '@example.test', 'Charlie ' . $token ),
		);

		$posts = array(
			101 => self::post_row(
				101,
				'post',
				'publish',
				1,
				'2021-01-15 10:00:00',
				'CDATA close ]]> marker ' . $token . ' ' . self::INVALID_MARK,
				'Export Post ' . $token,
				0
			),
			102 => self::post_row( 102, 'post', 'draft', 2, '2021-02-10 10:00:00', 'Draft body ' . $token, 'Draft Post ' . $token, 0 ),
			103 => self::post_row( 103, 'post', 'publish', 1, '2021-03-25 10:00:00', 'Late body ' . $token, 'Late Post ' . $token, 0 ),
			104 => self::post_row( 104, 'post', 'auto-draft', 3, '2021-01-12 10:00:00', 'Auto draft body ' . $token, 'Auto Draft ' . $token, 0 ),
			105 => self::post_row( 105, $custom_pt, 'publish', 3, '2021-01-20 10:00:00', 'Custom type body ' . $token, 'Custom Type ' . $token, 0 ),
			201 => self::post_row( 201, 'page', 'publish', 2, '2021-02-11 10:00:00', 'Page body ]]> ' . self::INVALID_MARK . ' ' . $token, 'Page ' . $token, 0 ),
			202 => self::post_row( 202, 'page', 'draft', 1, '2021-04-11 10:00:00', 'Draft page body ' . $token, 'Draft Page ' . $token, 0 ),
			301 => self::post_row( 301, 'attachment', 'inherit', 2, '2021-01-16 10:00:00', 'Attachment body ]]> ' . self::INVALID_MARK . ' ' . $token, 'Attachment One ' . $token, 101 ),
			302 => self::post_row( 302, 'attachment', 'inherit', 2, '2021-02-12 10:00:00', 'Attachment page body ]]> ' . self::INVALID_MARK . ' ' . $token, 'Attachment Two ' . $token, 201 ),
			303 => self::post_row( 303, 'attachment', 'inherit', 3, '2021-05-12 10:00:00', 'Attachment custom body ]]> ' . self::INVALID_MARK . ' ' . $token, 'Attachment Three ' . $token, 105 ),
		);

		$postmeta = array(
			self::meta_row( 1, 'post_id', 101, '_edit_lock', '1670000000:1' ),
			self::meta_row( 2, 'post_id', 101, 'keep_post_' . $token, 'kept post meta ]]> ' . self::INVALID_MARK ),
			self::meta_row( 3, 'post_id', 101, 'skip_post_' . $token, 'skipped post meta' ),
			self::meta_row( 4, 'post_id', 101, '_thumbnail_id', '301' ),
			self::meta_row( 5, 'post_id', 301, '_wp_attached_file', '2021/01/attachment-' . $token . '.txt' ),
			self::meta_row( 6, 'post_id', 302, '_wp_attached_file', '2021/02/page-attachment-' . $token . '.txt' ),
			self::meta_row( 7, 'post_id', 303, '_wp_attached_file', '2021/05/custom-attachment-' . $token . '.txt' ),
		);

		$termmeta = array(
			self::meta_row( 11, 'term_id', 10, 'keep_term_' . $token, 'kept term meta ]]> ' . self::INVALID_MARK ),
			self::meta_row( 12, 'term_id', 10, 'skip_term_' . $token, 'skipped term meta' ),
			self::meta_row( 13, 'term_id', 31, 'keep_custom_term_' . $token, 'kept custom term meta' ),
		);

		$comments = array(
			1001 => self::comment_row( 1001, 101, '1', 'Comment Author ' . $token, 'Comment content ]]> ' . self::INVALID_MARK ),
			1002 => self::comment_row( 1002, 101, 'spam', 'Spam Author ' . $token, 'Spam content ' . $token ),
		);

		$commentmeta = array(
			self::meta_row( 21, 'comment_id', 1001, 'keep_comment_' . $token, 'kept comment meta ]]> ' . self::INVALID_MARK ),
			self::meta_row( 22, 'comment_id', 1001, 'skip_comment_' . $token, 'skipped comment meta' ),
		);

		$relationships = array(
			array( 'object_id' => 101, 'term_taxonomy_id' => 100, 'term_order' => 0 ),
			array( 'object_id' => 101, 'term_taxonomy_id' => 101, 'term_order' => 1 ),
			array( 'object_id' => 101, 'term_taxonomy_id' => 200, 'term_order' => 2 ),
			array( 'object_id' => 101, 'term_taxonomy_id' => 300, 'term_order' => 3 ),
			array( 'object_id' => 101, 'term_taxonomy_id' => 301, 'term_order' => 4 ),
			array( 'object_id' => 102, 'term_taxonomy_id' => 100, 'term_order' => 0 ),
			array( 'object_id' => 103, 'term_taxonomy_id' => 100, 'term_order' => 0 ),
			array( 'object_id' => 103, 'term_taxonomy_id' => 101, 'term_order' => 1 ),
			array( 'object_id' => 104, 'term_taxonomy_id' => 101, 'term_order' => 0 ),
			array( 'object_id' => 105, 'term_taxonomy_id' => 301, 'term_order' => 0 ),
		);

		return array(
			'repoRoot'          => \ComponentFuzz\repo_root(),
			'scenario'          => $scenario,
			'token'             => $token,
			'customTaxonomy'    => $custom_tax,
			'customPostType'    => $custom_pt,
			'expectedFilename'  => 'component-fuzz-' . $token . '.xml',
			'uploadsBaseUrl'    => 'http://example.test/component-fuzz-uploads/' . $token,
			'autoDraftPostId'   => 104,
			'args'              => self::args_for_scenario( $scenario, $terms['categoryParent']['slug'] ),
			'users'             => $users,
			'posts'             => $posts,
			'terms'             => $terms,
			'postmeta'          => $postmeta,
			'termmeta'          => $termmeta,
			'comments'          => $comments,
			'commentmeta'       => $commentmeta,
			'relationships'     => $relationships,
			'exportFilterMarkers' => array(
				'titlePrefix'   => '[wxr-title-' . $token . '] ',
				'contentSuffix' => ' [wxr-content-' . $token . ']',
				'excerptSuffix' => ' [wxr-excerpt-' . $token . ']',
			),
			'keepKeys'          => array(
				'post'    => 'keep_post_' . $token,
				'term'    => 'keep_term_' . $token,
				'comment' => 'keep_comment_' . $token,
			),
			'skipKeys'          => array(
				'post'    => 'skip_post_' . $token,
				'term'    => 'skip_term_' . $token,
				'comment' => 'skip_comment_' . $token,
			),
			'exportableTypes'   => array( 'post', 'page', 'attachment', $custom_pt ),
			'contentCanExport'  => array(
				'post'       => true,
				'page'       => true,
				'attachment' => true,
				$custom_pt   => true,
			),
		);
	}

	private static function scenario_for_iteration( int $iteration ): string {
		$scenarios = array( 'all', 'post-filtered', 'page-filtered', 'attachment-filtered', 'post-default' );
		return $scenarios[ $iteration % count( $scenarios ) ];
	}

	private static function args_for_scenario( string $scenario, string $category_slug ): array {
		if ( 'post-filtered' === $scenario ) {
			return array(
				'content'    => 'post',
				'author'     => 1,
				'category'   => $category_slug,
				'start_date' => '2021-01-01',
				'end_date'   => '2021-01-20',
				'status'     => 'publish',
			);
		}

		if ( 'page-filtered' === $scenario ) {
			return array(
				'content'    => 'page',
				'author'     => 2,
				'category'   => false,
				'start_date' => '2021-02-01',
				'end_date'   => '2021-02-20',
				'status'     => 'publish',
			);
		}

		if ( 'attachment-filtered' === $scenario ) {
			return array(
				'content'    => 'attachment',
				'author'     => 2,
				'category'   => false,
				'start_date' => '2021-01-01',
				'end_date'   => '2021-02-28',
				'status'     => false,
			);
		}

		if ( 'post-default' === $scenario ) {
			return array(
				'content'    => 'post',
				'author'     => false,
				'category'   => false,
				'start_date' => false,
				'end_date'   => false,
				'status'     => false,
			);
		}

		return array(
			'content'    => 'all',
			'author'     => false,
			'category'   => false,
			'start_date' => false,
			'end_date'   => false,
			'status'     => false,
		);
	}

	private static function expected_exported_post_ids( array $case ): array {
		$args = $case['args'];
		$ids  = array();

		foreach ( $case['posts'] as $id => $post ) {
			if ( ! self::post_matches_export_args( $post, $case ) ) {
				continue;
			}
			$ids[] = (int) $id;
		}

		if ( ! in_array( $args['content'], array( 'all', 'attachment' ), true ) ) {
			$additional = array();
			foreach ( $case['posts'] as $id => $post ) {
				if ( 'attachment' === $post['post_type'] && in_array( (int) $post['post_parent'], $ids, true ) ) {
					$additional[] = (int) $id;
				}
			}
			foreach ( $case['postmeta'] as $meta ) {
				if ( '_thumbnail_id' === $meta['meta_key'] && in_array( (int) $meta['post_id'], $ids, true ) ) {
					$additional[] = (int) $meta['meta_value'];
				}
			}
			$ids = array_values( array_unique( array_merge( $ids, $additional ) ) );
		}

		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function post_matches_export_args( array $post, array $case ): bool {
		$args    = $case['args'];
		$content = $args['content'];

		if ( 'all' === $content ) {
			if ( ! in_array( $post['post_type'], $case['exportableTypes'], true ) ) {
				return false;
			}
		} elseif ( $post['post_type'] !== $content ) {
			return false;
		}

		if ( $args['status'] && in_array( $content, array( 'post', 'page' ), true ) ) {
			if ( $post['post_status'] !== $args['status'] ) {
				return false;
			}
		} elseif ( 'auto-draft' === $post['post_status'] ) {
			return false;
		}

		if ( $args['category'] && 'post' === $content ) {
			$term = self::term_by_slug( $case, $args['category'], 'category' );
			if ( null !== $term && ! self::post_has_term_taxonomy_id( (int) $post['ID'], (int) $term['term_taxonomy_id'], $case ) ) {
				return false;
			}
		}

		if ( in_array( $content, array( 'post', 'page', 'attachment' ), true ) ) {
			if ( $args['author'] && (int) $post['post_author'] !== (int) $args['author'] ) {
				return false;
			}
			if ( $args['start_date'] && $post['post_date'] < gmdate( 'Y-m-d', strtotime( $args['start_date'] ) ) ) {
				return false;
			}
			if ( $args['end_date'] && $post['post_date'] >= gmdate( 'Y-m-d', strtotime( '+1 month', strtotime( $args['end_date'] ) ) ) ) {
				return false;
			}
		}

		return true;
	}

	private static function expected_author_ids( array $case, array $post_ids ): array {
		$authors = array();
		foreach ( $post_ids as $post_id ) {
			$post = $case['posts'][ $post_id ] ?? null;
			if ( ! is_array( $post ) || 'auto-draft' === $post['post_status'] ) {
				continue;
			}
			$authors[] = (int) $post['post_author'];
		}

		return array_values( array_unique( $authors ) );
	}

	private static function expected_attachment_details( array $case, array $post_ids ): array {
		$expected = array();
		foreach ( $post_ids as $post_id ) {
			$post = $case['posts'][ $post_id ] ?? null;
			if ( ! is_array( $post ) || 'attachment' !== $post['post_type'] ) {
				continue;
			}

			$file = self::attached_file_for_post( $case, (int) $post_id );
			if ( null === $file ) {
				continue;
			}

			$expected[ (int) $post_id ] = array(
				'file' => $file,
				'url'  => self::expected_attachment_url( $case, $file ),
			);
		}

		ksort( $expected, SORT_NUMERIC );
		return $expected;
	}

	private static function attached_file_for_post( array $case, int $post_id ): ?string {
		foreach ( $case['postmeta'] as $meta ) {
			if ( (int) $meta['post_id'] === $post_id && '_wp_attached_file' === $meta['meta_key'] ) {
				return (string) $meta['meta_value'];
			}
		}

		return null;
	}

	private static function expected_attachment_url( array $case, string $file ): string {
		return rtrim( (string) $case['uploadsBaseUrl'], '/' ) . '/' . ltrim( $file, '/' );
	}

	private static function term_by_slug( array $case, string $slug, string $taxonomy ): ?array {
		foreach ( $case['terms'] as $term ) {
			if ( $slug === $term['slug'] && $taxonomy === $term['taxonomy'] ) {
				return $term;
			}
		}
		return null;
	}

	private static function post_has_term_taxonomy_id( int $post_id, int $term_taxonomy_id, array $case ): bool {
		foreach ( $case['relationships'] as $relationship ) {
			if ( (int) $relationship['object_id'] === $post_id && (int) $relationship['term_taxonomy_id'] === $term_taxonomy_id ) {
				return true;
			}
		}
		return false;
	}

	private static function parse_xml( string $xml ): array {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$parsed = simplexml_load_string( $xml );
		$errors = array_map(
			static fn ( \LibXMLError $error ): string => trim( $error->message ) . ' @' . $error->line . ':' . $error->column,
			libxml_get_errors()
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'ok'     => $parsed instanceof \SimpleXMLElement,
			'xml'    => $parsed instanceof \SimpleXMLElement ? $parsed : null,
			'errors' => array_slice( $errors, 0, 5 ),
		);
	}

	private static function extract_wxr_details( ?\SimpleXMLElement $xml ): array {
		$details = array(
			'postIds'         => array(),
			'statuses'        => array(),
			'authorIds'       => array(),
			'categorySlugs'   => array(),
			'termSlugs'       => array(),
			'postMetaKeys'    => array(),
			'termMetaKeys'    => array(),
			'commentMetaKeys' => array(),
			'postTypes'       => array(),
			'attachmentUrls'  => array(),
			'postMetaValues'  => array(),
			'titles'          => array(),
			'contents'        => array(),
			'excerpts'        => array(),
		);

		if ( ! $xml instanceof \SimpleXMLElement ) {
			return $details;
		}

		$channel = $xml->channel;
		$wp      = $channel->children( self::WXR_NS );

		foreach ( $wp->author as $author ) {
			$details['authorIds'][] = (int) $author->children( self::WXR_NS )->author_id;
		}
		foreach ( $wp->category as $category ) {
			$details['categorySlugs'][] = (string) $category->children( self::WXR_NS )->category_nicename;
			foreach ( $category->children( self::WXR_NS )->termmeta as $meta ) {
				$details['termMetaKeys'][] = (string) $meta->children( self::WXR_NS )->meta_key;
			}
		}
		foreach ( $wp->term as $term ) {
			$details['termSlugs'][] = (string) $term->children( self::WXR_NS )->term_slug;
			foreach ( $term->children( self::WXR_NS )->termmeta as $meta ) {
				$details['termMetaKeys'][] = (string) $meta->children( self::WXR_NS )->meta_key;
			}
		}

		foreach ( $channel->item as $item ) {
			$item_wp = $item->children( self::WXR_NS );
			$post_id                       = (int) $item_wp->post_id;
			$details['postIds'][]          = $post_id;
			$details['statuses'][]         = (string) $item_wp->status;
			$details['postTypes'][ $post_id ] = (string) $item_wp->post_type;
			if ( isset( $item_wp->attachment_url ) ) {
				$details['attachmentUrls'][ $post_id ] = (string) $item_wp->attachment_url;
			}
			$details['titles'][ $post_id ]   = (string) $item->title;
			$details['contents'][ $post_id ] = (string) $item->children( self::CONTENT_NS )->encoded;
			$details['excerpts'][ $post_id ] = (string) $item->children( self::EXCERPT_NS )->encoded;
			foreach ( $item_wp->postmeta as $meta ) {
				$meta_wp    = $meta->children( self::WXR_NS );
				$meta_key   = (string) $meta_wp->meta_key;
				$meta_value = (string) $meta_wp->meta_value;

				$details['postMetaKeys'][] = $meta_key;
				$details['postMetaValues'][ $post_id ][ $meta_key ][] = $meta_value;
			}
			foreach ( $item_wp->comment as $comment ) {
				foreach ( $comment->children( self::WXR_NS )->commentmeta as $meta ) {
					$details['commentMetaKeys'][] = (string) $meta->children( self::WXR_NS )->meta_key;
				}
			}
		}

		foreach ( array( 'postIds', 'authorIds' ) as $key ) {
			$details[ $key ] = array_values( array_map( 'intval', $details[ $key ] ) );
		}

		return $details;
	}

	private static function appears_before( array $values, string $parent, string $child ): bool {
		$parent_index = array_search( $parent, $values, true );
		$child_index  = array_search( $child, $values, true );

		return false !== $parent_index && false !== $child_index && $parent_index < $child_index;
	}

	private static function user_row( int $id, string $login, string $email, string $display_name ): array {
		return array(
			'ID'              => $id,
			'user_login'      => $login,
			'user_pass'       => '',
			'user_nicename'   => $login,
			'user_email'      => $email,
			'user_url'        => 'http://example.test/author/' . $login,
			'user_registered' => '2020-01-01 00:00:00',
			'user_status'     => 0,
			'display_name'    => $display_name,
			'first_name'      => strtok( $display_name, ' ' ),
			'last_name'       => 'Export',
		);
	}

	private static function post_row( int $id, string $type, string $status, int $author, string $date, string $content, string $title, int $parent ): array {
		return array(
			'ID'                    => $id,
			'post_author'           => $author,
			'post_date'             => $date,
			'post_date_gmt'         => $date,
			'post_content'          => $content,
			'post_title'            => $title,
			'post_excerpt'          => 'Excerpt ' . $title,
			'post_status'           => $status,
			'comment_status'        => 'open',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => strtolower( preg_replace( '/[^a-z0-9]+/', '-', $title ) ),
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $date,
			'post_modified_gmt'     => $date,
			'post_content_filtered' => '',
			'post_parent'           => $parent,
			'guid'                  => 'http://example.test/?p=' . $id,
			'menu_order'            => 0,
			'post_type'             => $type,
			'post_mime_type'        => 'attachment' === $type ? 'text/plain' : '',
			'comment_count'         => '0',
		);
	}

	private static function term_row( int $term_id, int $tt_id, string $taxonomy, string $slug, string $name, int $parent ): array {
		return array(
			'term_id'          => $term_id,
			'name'             => $name,
			'slug'             => $slug,
			'term_group'       => 0,
			'term_taxonomy_id' => $tt_id,
			'taxonomy'         => $taxonomy,
			'description'      => 'Description for ' . $name . ' ]]> ' . self::INVALID_MARK,
			'parent'           => $parent,
			'count'            => 1,
		);
	}

	private static function comment_row( int $id, int $post_id, string $approved, string $author, string $content ): array {
		return array(
			'comment_ID'           => $id,
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author,
			'comment_author_email' => strtolower( str_replace( ' ', '-', $author ) ) . '@example.test',
			'comment_author_url'   => 'http://example.test/comment-author',
			'comment_author_IP'    => '192.0.2.55',
			'comment_date'         => '2021-01-16 12:00:00',
			'comment_date_gmt'     => '2021-01-16 12:00:00',
			'comment_content'      => $content,
			'comment_karma'        => 0,
			'comment_approved'     => $approved,
			'comment_agent'        => 'ComponentFuzz',
			'comment_type'         => 'comment',
			'comment_parent'       => 0,
			'user_id'              => 0,
		);
	}

	private static function meta_row( int $id, string $object_column, int $object_id, string $key, string $value ): array {
		return array(
			'meta_id'        => $id,
			$object_column   => $object_id,
			'meta_key'       => $key,
			'meta_value'     => $value,
			'meta_object_id' => $object_id,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failures'] = array_slice( $failures, 0, 8 );

		return $ctx->result(
			$invariant,
			array() === $failures,
			$data
		);
	}

	private static function export_header_source_facts(): array {
		$path   = \ComponentFuzz\repo_root() . '/src/wp-admin/includes/export.php';
		$source = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( ! is_string( $source ) ) {
			return array(
				'path'     => $path,
				'readable' => false,
				'ordered'  => false,
			);
		}

		$filter_pos      = strpos( $source, "apply_filters( 'export_wp_filename'" );
		$disposition_pos = strpos( $source, "header( 'Content-Disposition: attachment; filename=' . \$filename )" );
		$type_pos        = strpos( $source, "header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true )" );

		return array(
			'path'                  => $path,
			'readable'              => true,
			'applyFilterPresent'    => false !== $filter_pos,
			'dispositionPresent'    => false !== $disposition_pos,
			'contentTypePresent'    => false !== $type_pos,
			'applyFilterOffset'     => false === $filter_pos ? null : $filter_pos,
			'dispositionOffset'     => false === $disposition_pos ? null : $disposition_pos,
			'contentTypeOffset'     => false === $type_pos ? null : $type_pos,
			'ordered'               => false !== $filter_pos
				&& false !== $disposition_pos
				&& false !== $type_pos
				&& $filter_pos < $disposition_pos
				&& $disposition_pos < $type_pos,
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

	private static function preview( string $value ): array {
		return array(
			'bytes'   => strlen( $value ),
			'preview' => \ComponentFuzz\preview_value( $value, self::PREVIEW_BYTES ),
		);
	}

	private static function case_summary( array $case ): array {
		return array(
			'scenario' => $case['scenario'] ?? null,
			'token'    => $case['token'] ?? null,
			'args'     => $case['args'] ?? array(),
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

	private static function snapshot_state(): array {
		$globals = array();
		foreach (
			array(
				'authordata',
				'post',
				'wp_actions',
				'wp_current_filter',
				'wp_filter',
				'wp_filters',
				'wp_query',
			) as $name
		) {
			$globals[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}

		return array(
			'globals' => $globals,
			'obLevel' => ob_get_level(),
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

		while ( ob_get_level() > $snapshot['obLevel'] ) {
			ob_end_clean();
		}
	}

	private static function state_matches( array $snapshot ): bool {
		if ( ob_get_level() !== $snapshot['obLevel'] ) {
			return false;
		}

		foreach ( $snapshot['globals'] as $name => $entry ) {
			if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
				return false;
			}
		}

		return true;
	}

	private static function child_program(): string {
		return <<<'PHP'
<?php
ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

$component_fuzz_wxr_raw = stream_get_contents( STDIN );
$component_fuzz_wxr_fixture = json_decode( $component_fuzz_wxr_raw, true );

if ( ! is_array( $component_fuzz_wxr_fixture ) || empty( $component_fuzz_wxr_fixture['repoRoot'] ) ) {
	echo json_encode(
		array(
			'ok'    => false,
			'error' => 'Invalid WXR fixture.',
		)
	);
	exit( 1 );
}

require_once $component_fuzz_wxr_fixture['repoRoot'] . '/tools/component-fuzz/lib/autoload.php';

\ComponentFuzz\WpBootstrap::load();

if ( ! class_exists( 'Component_Fuzz_WXR_WPDB_Double', false ) ) {
	class Component_Fuzz_WXR_WPDB_Double extends Component_Fuzz_WPDB_Stub {
		private array $fixture;

		public function __construct( array $fixture ) {
			$this->fixture = $this->replace_invalid_markers( $fixture );
			parent::__construct(
				array(
					'blog_charset'  => 'UTF-8',
					'blogname'      => 'Component Fuzz WXR',
					'blogdescription' => 'WXR export component fuzz fixture',
					'home'          => 'http://example.test',
					'siteurl'       => 'http://example.test',
					'language'      => 'en-US',
					'sticky_posts'  => array( 101 ),
					'upload_path'   => '',
					'upload_url_path' => $fixture['uploadsBaseUrl'] ?? '',
					'uploads_use_yearmonth_folders' => 1,
				)
			);
		}

		public function get_results( $query = null, $output = OBJECT ) {
			$this->last_query = (string) $query;
			$rows             = $this->select_rows( $this->last_query );
			$this->num_rows   = count( $rows );

			if ( null === $rows ) {
				return parent::get_results( $query, $output );
			}

			return $this->format_results( $rows, $output );
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			unset( $y );
			$this->last_query = (string) $query;
			$rows             = $this->select_rows( $this->last_query );

			if ( null === $rows ) {
				return parent::get_row( $query, $output );
			}

			$this->num_rows = count( $rows );
			if ( array() === $rows ) {
				return null;
			}

			return $this->format_row( reset( $rows ), $output );
		}

		public function get_col( $query = null, $x = 0 ) {
			$this->last_query = (string) $query;
			$rows             = $this->select_rows( $this->last_query );

			if ( null === $rows ) {
				return parent::get_col( $query, $x );
			}

			$this->num_rows = count( $rows );
			$values         = array();
			foreach ( $rows as $row ) {
				$row_values = array_values( $row );
				if ( array_key_exists( $x, $row_values ) ) {
					$values[] = $row_values[ $x ];
				}
			}
			return $values;
		}

		public function get_var( $query = null, $x = 0, $y = 0 ) {
			unset( $y );
			$row = $this->get_row( $query, ARRAY_N );
			if ( ! is_array( $row ) || ! array_key_exists( $x, $row ) ) {
				return null;
			}
			return $row[ $x ];
		}

		public function fixture(): array {
			return $this->fixture;
		}

		private function select_rows( string $query ): ?array {
			if ( preg_match( '/\bFROM\s+`?wp_options`?\b/i', $query ) ) {
				return null;
			}
			if ( preg_match( '/\bFROM\s+`?wp_posts`?\b/i', $query ) ) {
				return $this->project_posts( $this->select_posts( $query ), $query );
			}
			if ( preg_match( '/\bFROM\s+`?wp_users`?\b/i', $query ) ) {
				return $this->select_users( $query );
			}
			if ( preg_match( '/\bFROM\s+`?wp_comments`?\b/i', $query ) ) {
				return $this->select_comments( $query );
			}
			if ( preg_match( '/\bFROM\s+`?wp_postmeta`?\b/i', $query ) ) {
				return $this->select_meta( $query, 'postmeta', 'post_id' );
			}
			if ( preg_match( '/\bFROM\s+`?wp_termmeta`?\b/i', $query ) ) {
				return $this->select_meta( $query, 'termmeta', 'term_id' );
			}
			if ( preg_match( '/\bFROM\s+`?wp_commentmeta`?\b/i', $query ) ) {
				return $this->select_meta( $query, 'commentmeta', 'comment_id' );
			}
			if ( preg_match( '/\bFROM\s+`?wp_usermeta`?\b/i', $query ) ) {
				return array();
			}

			return null;
		}

		private function select_posts( string $query ): array {
			$rows = array_values( $this->fixture['posts'] );

			foreach ( array( 'ID', 'post_parent', 'post_author', 'post_type', 'post_status' ) as $column ) {
				$value = $this->compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $column, $value ): bool {
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			foreach ( array( 'ID', 'post_parent', 'post_type' ) as $column ) {
				$values = $this->in_values( $query, $column );
				if ( array() === $values ) {
					continue;
				}
				$value_map = array_fill_keys( array_map( 'strval', $values ), true );
				$rows      = array_filter(
					$rows,
					static function ( array $row ) use ( $column, $value_map ): bool {
						return isset( $value_map[ (string) $row[ $column ] ] );
					}
				);
			}

			$status_not = $this->not_compare_value( $query, 'post_status' );
			if ( null !== $status_not ) {
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $status_not ): bool {
						return (string) $row['post_status'] !== (string) $status_not;
					}
				);
			}

			$start = $this->date_compare_value( $query, 'post_date', '>=' );
			if ( null !== $start ) {
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $start ): bool {
						return (string) $row['post_date'] >= $start;
					}
				);
			}

			$end = $this->date_compare_value( $query, 'post_date', '<' );
			if ( null !== $end ) {
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $end ): bool {
						return (string) $row['post_date'] < $end;
					}
				);
			}

			$term_taxonomy_id = $this->compare_value( $query, 'term_taxonomy_id' );
			if ( null !== $term_taxonomy_id ) {
				$allowed = array();
				foreach ( $this->fixture['relationships'] as $relationship ) {
					if ( (int) $relationship['term_taxonomy_id'] === (int) $term_taxonomy_id ) {
						$allowed[ (int) $relationship['object_id'] ] = true;
					}
				}
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $allowed ): bool {
						return isset( $allowed[ (int) $row['ID'] ] );
					}
				);
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return (int) $a['ID'] <=> (int) $b['ID'];
				}
			);

			return array_values( $rows );
		}

		private function project_posts( array $rows, string $query ): array {
			if ( preg_match( '/SELECT\s+DISTINCT\s+post_author\b/i', $query ) ) {
				$out = array();
				foreach ( $rows as $row ) {
					$out[ (int) $row['post_author'] ] = array( 'post_author' => (int) $row['post_author'] );
				}
				return array_values( $out );
			}

			if ( preg_match( '/SELECT\s+ID\b/i', $query ) ) {
				return $this->project_rows( $rows, array( 'ID' ) );
			}

			return $rows;
		}

		private function select_users( string $query ): array {
			$rows = array_values( $this->fixture['users'] );
			foreach ( array( 'ID', 'user_login', 'user_nicename', 'user_email' ) as $column ) {
				$value = $this->compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $column, $value ): bool {
						return 'user_email' === $column
							? 0 === strcasecmp( (string) $row[ $column ], (string) $value )
							: (string) $row[ $column ] === (string) $value;
					}
				);
			}

			return array_values( $rows );
		}

		private function select_comments( string $query ): array {
			$rows = array_values( $this->fixture['comments'] );
			foreach ( array( 'comment_ID', 'comment_post_ID', 'comment_approved' ) as $column ) {
				$value = $this->compare_value( $query, $column );
				if ( null === $value ) {
					continue;
				}
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $column, $value ): bool {
						return (string) $row[ $column ] === (string) $value;
					}
				);
			}

			if ( preg_match( '/comment_approved\s*<>\s*(\'(?:\\\\.|[^\'\\\\])*\'|-?\d+)/i', $query, $matches ) ) {
				$not_approved = $this->unquote_sql_value( $matches[1] );
				$rows         = array_filter(
					$rows,
					static function ( array $row ) use ( $not_approved ): bool {
						return (string) $row['comment_approved'] !== (string) $not_approved;
					}
				);
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return (int) $a['comment_ID'] <=> (int) $b['comment_ID'];
				}
			);

			return array_values( $rows );
		}

		private function select_meta( string $query, string $bucket, string $object_column ): array {
			$rows = array_values( $this->fixture[ $bucket ] );

			$object_id = $this->compare_value( $query, $object_column );
			if ( null !== $object_id ) {
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $object_column, $object_id ): bool {
						return (int) $row[ $object_column ] === (int) $object_id;
					}
				);
			}

			$object_ids = $this->in_values( $query, $object_column );
			if ( array() !== $object_ids ) {
				$map  = array_fill_keys( array_map( 'intval', $object_ids ), true );
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $object_column, $map ): bool {
						return isset( $map[ (int) $row[ $object_column ] ] );
					}
				);
			}

			$meta_key = $this->compare_value( $query, 'meta_key' );
			if ( null !== $meta_key ) {
				$rows = array_filter(
					$rows,
					static function ( array $row ) use ( $meta_key ): bool {
						return (string) $row['meta_key'] === (string) $meta_key;
					}
				);
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return (int) $a['meta_id'] <=> (int) $b['meta_id'];
				}
			);

			if ( preg_match( '/SELECT\s+meta_value\b/i', $query ) ) {
				return $this->project_rows( $rows, array( 'meta_value' ) );
			}

			if ( preg_match( '/SELECT\s+' . preg_quote( $object_column, '/' ) . '\s*,\s*meta_key\s*,\s*meta_value\b/i', $query ) ) {
				return $this->project_rows( $rows, array( $object_column, 'meta_key', 'meta_value' ) );
			}

			return $rows;
		}

		private function compare_value( string $query, string $column ): ?string {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?<![a-z0-9_])(?:`?[a-z0-9_]+`?\.)?`?' . $column . '`?\s*=\s*(\'(?:\\\\.|[^\'\\\\])*\'|-?\d+)/i', $query, $matches ) ) {
				return $this->unquote_sql_value( $matches[1] );
			}
			return null;
		}

		private function not_compare_value( string $query, string $column ): ?string {
			$column = preg_quote( $column, '/' );
			if ( preg_match( '/(?<![a-z0-9_])(?:`?[a-z0-9_]+`?\.)?`?' . $column . '`?\s*!=\s*(\'(?:\\\\.|[^\'\\\\])*\'|-?\d+)/i', $query, $matches ) ) {
				return $this->unquote_sql_value( $matches[1] );
			}
			return null;
		}

		private function date_compare_value( string $query, string $column, string $operator ): ?string {
			$column   = preg_quote( $column, '/' );
			$operator = preg_quote( $operator, '/' );
			if ( preg_match( '/(?<![a-z0-9_])(?:`?[a-z0-9_]+`?\.)?`?' . $column . '`?\s*' . $operator . '\s*(\'(?:\\\\.|[^\'\\\\])*\'|-?\d+)/i', $query, $matches ) ) {
				return $this->unquote_sql_value( $matches[1] );
			}
			return null;
		}

		private function in_values( string $query, string $column ): array {
			$column = preg_quote( $column, '/' );
			if ( ! preg_match( '/(?<![a-z0-9_])(?:`?[a-z0-9_]+`?\.)?`?' . $column . '`?\s+IN\s*\(([^)]*)\)/i', $query, $matches ) ) {
				return array();
			}
			return $this->csv_values( $matches[1] );
		}

		private function csv_values( string $csv ): array {
			if ( ! preg_match_all( '/\'((?:\\\\.|[^\'\\\\])*)\'|"([^"]*)"|(-?\d+)/', $csv, $matches, PREG_SET_ORDER ) ) {
				return array();
			}

			return array_map(
				function ( array $match ): string {
					if ( isset( $match[1] ) && '' !== $match[1] ) {
						return stripslashes( $match[1] );
					}
					if ( isset( $match[2] ) && '' !== $match[2] ) {
						return $match[2];
					}
					return $match[3];
				},
				$matches
			);
		}

		private function unquote_sql_value( string $value ): string {
			$value = trim( $value );
			if ( strlen( $value ) >= 2 && "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) {
				return stripslashes( substr( $value, 1, -1 ) );
			}
			return $value;
		}

		private function project_rows( array $rows, array $columns ): array {
			$projected = array();
			foreach ( $rows as $row ) {
				$out = array();
				foreach ( $columns as $column ) {
					$out[ $column ] = $row[ $column ] ?? null;
				}
				$projected[] = $out;
			}
			return $projected;
		}

		private function format_row( array $row, $output ) {
			if ( ARRAY_A === $output ) {
				return $row;
			}
			if ( ARRAY_N === $output ) {
				return array_values( $row );
			}
			return (object) $row;
		}

		private function format_results( array $rows, $output ): array {
			if ( ARRAY_A === $output || ARRAY_N === $output ) {
				return array_map(
					function ( array $row ) use ( $output ) {
						return $this->format_row( $row, $output );
					},
					$rows
				);
			}
			return array_map(
				static function ( array $row ): object {
					return (object) $row;
				},
				$rows
			);
		}

		private function replace_invalid_markers( array $value ): array {
			array_walk_recursive(
				$value,
				static function ( &$item ): void {
					if ( is_string( $item ) ) {
						$item = str_replace( '__COMPONENT_FUZZ_INVALID_UTF8__', "\xC3\x28", $item );
					}
				}
			);
			return $value;
		}
	}
}

function component_fuzz_wxr_snapshot_state(): array {
	$globals = array();
	foreach ( array( 'authordata', 'post', 'wpdb', 'wp_actions', 'wp_current_filter', 'wp_filter', 'wp_filters', 'wp_query', 'wp_rewrite' ) as $name ) {
		$globals[ $name ] = array(
			'exists' => array_key_exists( $name, $GLOBALS ),
			'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
		);
	}

	return array(
		'globals' => $globals,
		'obLevel' => ob_get_level(),
	);
}

function component_fuzz_wxr_restore_state( array $snapshot ): void {
	foreach ( $snapshot['globals'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			$GLOBALS[ $name ] = $entry['value'];
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}

	while ( ob_get_level() > $snapshot['obLevel'] ) {
		ob_end_clean();
	}
}

function component_fuzz_wxr_state_matches( array $snapshot ): bool {
	if ( ob_get_level() !== $snapshot['obLevel'] ) {
		return false;
	}

	foreach ( $snapshot['globals'] as $name => $entry ) {
		if ( $entry['exists'] !== array_key_exists( $name, $GLOBALS ) ) {
			return false;
		}
	}

	return true;
}

function component_fuzz_wxr_prepare_runtime( array $fixture ): void {
	global $component_fuzz_wxr_events, $wpdb;

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	if ( function_exists( 'create_initial_post_types' ) ) {
		create_initial_post_types();
	}
	if ( function_exists( 'create_initial_taxonomies' ) ) {
		create_initial_taxonomies();
	}

	register_post_type(
		$fixture['customPostType'],
		array(
			'public'     => true,
			'show_ui'    => false,
			'rewrite'    => false,
			'query_var'  => false,
			'can_export' => true,
			'supports'   => array( 'title', 'editor', 'excerpt', 'author', 'comments' ),
		)
	);
	register_taxonomy(
		$fixture['customTaxonomy'],
		array( 'post', $fixture['customPostType'] ),
		array(
			'public'       => true,
			'hierarchical' => true,
			'rewrite'      => false,
			'query_var'    => false,
		)
	);

	$wpdb = new Component_Fuzz_WXR_WPDB_Double( $fixture );
	$GLOBALS['wpdb'] = $wpdb;
	$fixture = $wpdb->fixture();

	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_rewrite'] = new WP_Rewrite();
	unset( $GLOBALS['post'], $GLOBALS['authordata'] );

	foreach ( $fixture['posts'] as $post ) {
		wp_cache_set( (int) $post['ID'], (object) $post, 'posts' );
	}
	foreach ( $fixture['comments'] as $comment ) {
		wp_cache_set( (int) $comment['comment_ID'], (object) $comment, 'comment' );
	}
	foreach ( $fixture['terms'] as $term ) {
		wp_cache_set( (int) $term['term_id'], (object) $term, 'terms' );
	}
	foreach ( $fixture['users'] as $user ) {
		update_user_caches( (object) $user );
	}

	$component_fuzz_wxr_events = array(
		'postmeta'    => array(),
		'termmeta'    => array(),
		'commentmeta' => array(),
		'filename'    => array(),
		'title'       => array(),
		'content'     => array(),
		'excerpt'     => array(),
	);

	add_filter(
		'export_wp_filename',
		static function ( string $filename, string $sitename, string $date ) use ( $fixture, &$component_fuzz_wxr_events ): string {
			$filteredFilename = $fixture['expectedFilename'];
			$component_fuzz_wxr_events['filename'][] = compact( 'filename', 'sitename', 'date', 'filteredFilename' );
			return $filteredFilename;
		},
		10,
		3
	);
	add_filter(
		'terms_pre_query',
		static function ( $terms, WP_Term_Query $query ) use ( $fixture ) {
			unset( $terms );
			return component_fuzz_wxr_terms_for_query( $fixture, $query->query_vars );
		},
		10,
		2
	);
	add_filter(
		'the_permalink_rss',
		static function (): string {
			$post = get_post();
			return $post ? 'http://example.test/?p=' . (int) $post->ID : 'http://example.test/';
		}
	);
	add_filter(
		'the_title_export',
		static function ( string $title ) use ( $fixture, &$component_fuzz_wxr_events ): string {
			$component_fuzz_wxr_events['title'][] = $title;
			return $fixture['exportFilterMarkers']['titlePrefix'] . $title;
		}
	);
	add_filter(
		'the_content_export',
		static function ( string $content ) use ( $fixture, &$component_fuzz_wxr_events ): string {
			$component_fuzz_wxr_events['content'][] = strlen( $content );
			return $content . $fixture['exportFilterMarkers']['contentSuffix'];
		}
	);
	add_filter(
		'the_excerpt_export',
		static function ( string $excerpt ) use ( $fixture, &$component_fuzz_wxr_events ): string {
			$component_fuzz_wxr_events['excerpt'][] = $excerpt;
			return $excerpt . $fixture['exportFilterMarkers']['excerptSuffix'];
		}
	);
	add_filter(
		'wxr_export_skip_postmeta',
		static function ( bool $skip, string $meta_key ) use ( $fixture, &$component_fuzz_wxr_events ): bool {
			$component_fuzz_wxr_events['postmeta'][] = $meta_key;
			return $skip || $fixture['skipKeys']['post'] === $meta_key;
		},
		9,
		2
	);
	add_filter(
		'wxr_export_skip_termmeta',
		static function ( bool $skip, string $meta_key ) use ( $fixture, &$component_fuzz_wxr_events ): bool {
			$component_fuzz_wxr_events['termmeta'][] = $meta_key;
			return $skip || $fixture['skipKeys']['term'] === $meta_key;
		},
		10,
		2
	);
	add_filter(
		'wxr_export_skip_commentmeta',
		static function ( bool $skip, string $meta_key ) use ( $fixture, &$component_fuzz_wxr_events ): bool {
			$component_fuzz_wxr_events['commentmeta'][] = $meta_key;
			return $skip || $fixture['skipKeys']['comment'] === $meta_key;
		},
		10,
		2
	);
}

function component_fuzz_wxr_terms_for_query( array $fixture, array $query_vars ): array {
	$taxonomies = array_filter( array_map( 'strval', (array) ( $query_vars['taxonomy'] ?? array() ) ) );
	$terms      = array_values( $fixture['terms'] );

	if ( array() !== $taxonomies ) {
		$taxonomy_map = array_fill_keys( $taxonomies, true );
		$terms        = array_filter(
			$terms,
			static function ( array $term ) use ( $taxonomy_map ): bool {
				return isset( $taxonomy_map[ $term['taxonomy'] ] );
			}
		);
	}

	foreach ( array( 'slug', 'name' ) as $field ) {
		if ( empty( $query_vars[ $field ] ) ) {
			continue;
		}
		$values = array_fill_keys( array_map( 'strval', (array) $query_vars[ $field ] ), true );
		$terms  = array_filter(
			$terms,
			static function ( array $term ) use ( $field, $values ): bool {
				return isset( $values[ (string) $term[ $field ] ] );
			}
		);
	}

	if ( ! empty( $query_vars['include'] ) ) {
		$include = array_fill_keys( array_map( 'intval', (array) $query_vars['include'] ), true );
		$terms   = array_filter(
			$terms,
			static function ( array $term ) use ( $include ): bool {
				return isset( $include[ (int) $term['term_id'] ] );
			}
		);
	}

	if ( '' !== (string) ( $query_vars['parent'] ?? '' ) ) {
		$parent = (int) $query_vars['parent'];
		$terms  = array_filter(
			$terms,
			static function ( array $term ) use ( $parent ): bool {
				return (int) $term['parent'] === $parent;
			}
		);
	}

	if ( ! empty( $query_vars['object_ids'] ) ) {
		$object_ids = array_fill_keys( array_map( 'intval', (array) $query_vars['object_ids'] ), true );
		$allowed_tt = array();
		foreach ( $fixture['relationships'] as $relationship ) {
			if ( isset( $object_ids[ (int) $relationship['object_id'] ] ) ) {
				$allowed_tt[ (int) $relationship['term_taxonomy_id'] ] = (int) $relationship['object_id'];
			}
		}
		$terms = array_filter(
			$terms,
			static function ( array $term ) use ( $allowed_tt ): bool {
				return isset( $allowed_tt[ (int) $term['term_taxonomy_id'] ] );
			}
		);
		foreach ( $terms as &$term ) {
			$term['object_id'] = $allowed_tt[ (int) $term['term_taxonomy_id'] ];
		}
		unset( $term );
	}

	$order_by = strtolower( (string) ( $query_vars['orderby'] ?? 'term_id' ) );
	usort(
		$terms,
		static function ( array $a, array $b ) use ( $order_by ): int {
			if ( 'name' === $order_by ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
			if ( 'slug' === $order_by ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
			return (int) $a['term_id'] <=> (int) $b['term_id'];
		}
	);

	return array_map(
		static function ( array $term ): WP_Term {
			return new WP_Term( (object) $term );
		},
		array_values( $terms )
	);
}

$component_fuzz_wxr_snapshot = component_fuzz_wxr_snapshot_state();
$component_fuzz_wxr_result   = array(
	'ok'                              => false,
	'xmlBase64'                       => '',
	'headers'                         => array(),
	'headersObservable'               => false,
	'filenameFilter'                  => null,
	'filterEvents'                    => array(),
	'stateRestored'                   => false,
	'outputBufferRestored'            => false,
	'containsInvalidInput'            => false,
	'containsCdataTerminatorInput'    => false,
	'exportCalls'                     => 0,
	'sapi'                            => PHP_SAPI,
);

try {
	component_fuzz_wxr_prepare_runtime( $component_fuzz_wxr_fixture );
	require_once $component_fuzz_wxr_fixture['repoRoot'] . '/src/wp-admin/includes/export.php';

	$component_fuzz_wxr_export_calls = 0;
	add_action(
		'export_wp',
		static function () use ( &$component_fuzz_wxr_export_calls ): void {
			++$component_fuzz_wxr_export_calls;
		}
	);

	ob_start();
	export_wp( $component_fuzz_wxr_fixture['args'] );
	$component_fuzz_wxr_xml = ob_get_clean();

	$component_fuzz_wxr_headers = function_exists( 'xdebug_get_headers' ) ? xdebug_get_headers() : headers_list();
	$component_fuzz_wxr_result  = array(
		'ok'                           => true,
		'xmlBase64'                    => base64_encode( $component_fuzz_wxr_xml ),
		'headers'                      => array_values( array_map( 'strval', $component_fuzz_wxr_headers ) ),
		'headersObservable'            => array() !== $component_fuzz_wxr_headers,
		'blogCharset'                  => get_option( 'blog_charset' ),
		'expectedContentDisposition'    => 'Content-Disposition: attachment; filename=' . $component_fuzz_wxr_fixture['expectedFilename'],
		'expectedContentType'           => 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ),
		'filenameFilter'               => $component_fuzz_wxr_events['filename'][0] ?? null,
		'filterEvents'                 => $component_fuzz_wxr_events,
		'containsInvalidInput'         => str_contains( serialize( $GLOBALS['wpdb']->fixture() ), "\xC3\x28" ),
		'containsCdataTerminatorInput' => str_contains( serialize( $GLOBALS['wpdb']->fixture() ), ']]>' ),
		'exportCalls'                  => $component_fuzz_wxr_export_calls,
		'sapi'                         => PHP_SAPI,
	);
} catch ( Throwable $e ) {
	while ( ob_get_level() > $component_fuzz_wxr_snapshot['obLevel'] ) {
		ob_end_clean();
	}
	$component_fuzz_wxr_result = array(
		'ok'        => false,
		'error'     => get_class( $e ) . ': ' . $e->getMessage(),
		'file'      => $e->getFile(),
		'line'      => $e->getLine(),
		'sapi'      => PHP_SAPI,
		'xmlBase64' => '',
	);
} finally {
	component_fuzz_wxr_restore_state( $component_fuzz_wxr_snapshot );
	$component_fuzz_wxr_result['stateRestored']        = component_fuzz_wxr_state_matches( $component_fuzz_wxr_snapshot );
	$component_fuzz_wxr_result['outputBufferRestored'] = ob_get_level() === $component_fuzz_wxr_snapshot['obLevel'];
}

echo json_encode( $component_fuzz_wxr_result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
PHP;
	}
}
