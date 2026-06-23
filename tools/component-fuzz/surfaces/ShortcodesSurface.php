<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes the no-DB shortcode registry, parser, renderer, and stripping helpers.
 */
final class ShortcodesSurface {
	public const NAME = 'shortcodes';

	private const ATTRIBUTE_CASES = 8;
	private const PREVIEW_BYTES    = 160;

	/**
	 * Runs one deterministic shortcode fuzz iteration.
	 *
	 * @param \ComponentFuzz\FuzzContext $ctx Fuzzer context supplied by the runner.
	 * @return array<int,array<string,mixed>> Structured invariant rows.
	 */
	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$missing = self::missing_requirements();
		if ( array() !== $missing ) {
			return array(
				$ctx->skip(
					'shortcodes.bootstrap-apis-available',
					'Required WordPress shortcode APIs are unavailable.',
					array( 'missing' => $missing )
				),
			);
		}

		$snapshot = self::snapshot_registry();
		$rows     = array();

		try {
			$attribute_cases = self::attribute_cases( $ctx->fork( 'attribute-cases' ) );

			$rows[] = self::check_registry_lifecycle( $ctx->fork( 'registry' ) );
			$rows[] = self::check_attribute_parsing( $ctx->fork( 'attribute-parsing' ), $attribute_cases );
			$rows[] = self::check_shortcode_atts_defaults( $ctx->fork( 'attribute-defaults' ), $attribute_cases );
			$rows[] = self::check_shortcode_atts_filter_contract( $ctx->fork( 'attribute-filter' ), $attribute_cases );
			$rows[] = self::check_rendering_contracts( $ctx->fork( 'rendering' ), $attribute_cases );
			$rows[] = self::check_shortcode_tag_filter_contracts( $ctx->fork( 'tag-filters' ), $attribute_cases );
			$rows[] = self::check_escaped_shortcodes( $ctx->fork( 'escaping' ), $attribute_cases );
			$rows[] = self::check_html_attribute_behavior( $ctx->fork( 'html-attributes' ) );
			$rows[] = self::check_strip_and_has_shortcode( $ctx->fork( 'strip-has' ), $attribute_cases );
			$rows[] = self::check_tag_discovery_and_apply_alias( $ctx->fork( 'tag-discovery' ), $attribute_cases );
			$rows[] = self::check_malformed_and_nested_cases( $ctx->fork( 'malformed-nested' ) );
		} catch ( \Throwable $e ) {
			$rows[] = $ctx->fail(
				'shortcodes.surface-no-throw',
				array(
					'throwable' => self::describe_throwable( $e ),
				)
			);
		} finally {
			self::restore_registry( $snapshot );
		}

		$rows[] = self::check_registry_restored( $ctx, $snapshot );

		return $rows;
	}

	private static function missing_requirements(): array {
		$missing = array();

		foreach (
			array(
				'add_shortcode',
				'remove_shortcode',
				'remove_all_shortcodes',
				'add_filter',
				'remove_filter',
				'shortcode_exists',
				'get_shortcode_regex',
				'get_shortcode_tags_in_content',
				'get_shortcode_atts_regex',
				'shortcode_parse_atts',
				'shortcode_atts',
				'do_shortcode',
				'apply_shortcodes',
				'do_shortcodes_in_html_tags',
				'strip_shortcodes',
				'has_shortcode',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				$missing[] = "function {$function}";
			}
		}

		return $missing;
	}

	private static function check_registry_lifecycle( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag_a       = self::tag( $ctx, 'registry-a' );
		$tag_b       = self::tag( $ctx, 'registry-b' );
		$start       = array(
			'preexisting_shortcode' => static function () {
				return 'preexisting';
			},
		);
		$callback_a  = static function () {
			return 'first';
		};
		$callback_b  = static function () {
			return 'second';
		};
		$replacement = static function () {
			return 'replacement';
		};
		$failures    = array();

		self::replace_registry( $start );

		\add_shortcode( $tag_a, $callback_a );
		\add_shortcode( $tag_b, $callback_b );
		$after_add = $GLOBALS['shortcode_tags'];

		self::collect_failure(
			$failures,
			\shortcode_exists( $tag_a )
				&& \shortcode_exists( $tag_b )
				&& \shortcode_exists( 'preexisting_shortcode' )
				&& isset( $after_add[ $tag_a ], $after_add[ $tag_b ], $after_add['preexisting_shortcode'] )
				&& $after_add[ $tag_a ] === $callback_a
				&& $after_add[ $tag_b ] === $callback_b,
			'add_shortcode stores callbacks without disturbing existing registry entries',
			array(
				'tags'        => array( $tag_a, $tag_b ),
				'registryKeys' => array_keys( $after_add ),
			)
		);

		\add_shortcode( $tag_a, $replacement );
		self::collect_failure(
			$failures,
			\shortcode_exists( $tag_a )
				&& isset( $GLOBALS['shortcode_tags'][ $tag_a ] )
				&& $GLOBALS['shortcode_tags'][ $tag_a ] === $replacement,
			're-registering a shortcode replaces only that tag callback',
			array(
				'tag'          => $tag_a,
				'registryKeys' => array_keys( $GLOBALS['shortcode_tags'] ),
			)
		);

		\remove_shortcode( $tag_a );
		self::collect_failure(
			$failures,
			! \shortcode_exists( $tag_a )
				&& \shortcode_exists( $tag_b )
				&& \shortcode_exists( 'preexisting_shortcode' ),
			'remove_shortcode removes one tag and leaves unrelated entries in place',
			array(
				'removedTag'   => $tag_a,
				'remainingTag' => $tag_b,
				'registryKeys' => array_keys( $GLOBALS['shortcode_tags'] ),
			)
		);

		\remove_all_shortcodes();
		self::collect_failure(
			$failures,
			array() === $GLOBALS['shortcode_tags']
				&& ! \shortcode_exists( $tag_b )
				&& ! \shortcode_exists( 'preexisting_shortcode' ),
			'remove_all_shortcodes clears the local registry',
			array(
				'registry' => $GLOBALS['shortcode_tags'],
			)
		);

		self::replace_registry( $start );
		self::collect_failure(
			$failures,
			$GLOBALS['shortcode_tags'] === $start,
			'local registry mutations are reversible within the surface',
			array(
				'registryKeys' => array_keys( $GLOBALS['shortcode_tags'] ),
			)
		);

		return self::result(
			$ctx,
			'shortcodes.registry-lifecycle-local',
			$failures,
			array(
				'tags' => array( $tag_a, $tag_b ),
			)
		);
	}

	private static function check_attribute_parsing( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures    = array();
		$regex       = \get_shortcode_atts_regex();
		$match_total = 0;

		foreach ( $cases as $index => $case ) {
			$matches     = array();
			$match_count = preg_match_all( $regex, $case['text'], $matches, PREG_SET_ORDER );
			$error       = preg_last_error();

			if ( false === $match_count || PREG_NO_ERROR !== $error ) {
				$failures[] = array(
					'name'          => 'attribute-regex-error',
					'message'       => 'get_shortcode_atts_regex produced a PCRE error for a generated attribute list.',
					'caseIndex'     => $index,
					'pregLastError' => $error,
					'text'          => self::describe_string( $case['text'] ),
				);
				continue;
			}

			$match_total += $match_count;
			$actual       = \shortcode_parse_atts( $case['text'] );
			$split        = self::split_attributes( $actual );
			$expected     = array(
				'named'      => $case['expectedNamed'],
				'positional' => $case['expectedPositional'],
			);

			if ( $split !== $expected ) {
				$failures[] = array(
					'name'       => 'attribute-parse-mismatch',
					'message'    => 'shortcode_parse_atts did not match the generated expected attribute map.',
					'caseIndex'  => $index,
					'text'       => self::describe_string( $case['text'] ),
					'expected'   => $expected,
					'actual'     => $split,
					'difference' => self::first_value_difference( $expected, $split ),
				);
			}
		}

		return self::result(
			$ctx,
			'shortcodes.attributes-parse-generated-map',
			$failures,
			array(
				'cases'        => count( $cases ),
				'regexMatches' => $match_total,
			)
		);
	}

	private static function check_shortcode_atts_defaults( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$failures = array();
		$tag      = self::tag( $ctx, 'defaults' );

		foreach ( $cases as $index => $case ) {
			$known_keys = array_keys( $case['expectedNamed'] );
			$pairs      = array(
				$known_keys[0]          => 'default-one',
				$known_keys[1]          => 'default-two',
				'default_only_' . $index => 'fallback-' . $index,
			);
			$atts       = $case['expectedNamed'] + array(
				'unknown_' . $index => 'drop-me',
				0                   => 'drop-positional',
			);
			$actual     = \shortcode_atts( $pairs, $atts, $tag );
			$expected   = array(
				$known_keys[0]          => $case['expectedNamed'][ $known_keys[0] ],
				$known_keys[1]          => $case['expectedNamed'][ $known_keys[1] ],
				'default_only_' . $index => 'fallback-' . $index,
			);

			if ( $actual !== $expected ) {
				$failures[] = array(
					'name'       => 'shortcode-atts-merge-mismatch',
					'message'    => 'shortcode_atts failed to preserve known keys, fill defaults, or drop unknowns.',
					'caseIndex'  => $index,
					'pairs'      => $pairs,
					'atts'       => $atts,
					'expected'   => $expected,
					'actual'     => $actual,
					'difference' => self::first_value_difference( $expected, $actual ),
				);
			}
		}

		return self::result(
			$ctx,
			'shortcodes.attributes-default-merge',
			$failures,
			array(
				'cases' => count( $cases ),
				'tag'   => $tag,
			)
		);
	}

	private static function check_shortcode_atts_filter_contract( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$tag        = self::tag( $ctx, 'atts-filter' );
		$other_tag  = self::tag( $ctx, 'atts-other' );
		$case       = $cases[4];
		$known_keys = array_keys( $case['expectedNamed'] );
		$pairs      = array(
			$known_keys[0] => 'default-one',
			$known_keys[1] => 'default-two',
			'filter_only'  => 'default-filter',
		);
		$atts       = $case['expectedNamed'] + array(
			'unknown_filter' => 'drop-me',
			0                => 'drop-positional',
		);
		$seen       = array();
		$filter     = static function ( $out, $filter_pairs, $filter_atts, $shortcode ) use ( &$seen ) {
			$seen[] = array(
				'out'       => $out,
				'pairs'     => $filter_pairs,
				'atts'      => $filter_atts,
				'shortcode' => $shortcode,
			);

			$out['filter_only'] = 'filtered-' . (string) $shortcode;
			$out['added_by_filter'] = count( $filter_atts );
			return $out;
		};

		\add_filter( "shortcode_atts_{$tag}", $filter, 10, 4 );

		try {
			$filtered       = \shortcode_atts( $pairs, $atts, $tag );
			$unfiltered_tag = \shortcode_atts( $pairs, $atts, $other_tag );
			$no_tag         = \shortcode_atts( $pairs, $atts, '' );
		} finally {
			\remove_filter( "shortcode_atts_{$tag}", $filter, 10 );
		}

		$base_expected = array(
			$known_keys[0] => $case['expectedNamed'][ $known_keys[0] ],
			$known_keys[1] => $case['expectedNamed'][ $known_keys[1] ],
			'filter_only'  => 'default-filter',
		);
		$expected      = $base_expected + array(
			'added_by_filter' => count( $atts ),
		);
		$expected['filter_only'] = 'filtered-' . $tag;
		$failures = array();

		self::collect_failure(
			$failures,
			$expected === $filtered
				&& $base_expected === $unfiltered_tag
				&& $base_expected === $no_tag
				&& 1 === count( $seen )
				&& $seen[0]['out'] === $base_expected
				&& $seen[0]['pairs'] === $pairs
				&& $seen[0]['atts'] === $atts
				&& $seen[0]['shortcode'] === $tag,
			'shortcode_atts dynamic filter receives merged values and remains scoped to its tag',
			array(
				'tag'           => $tag,
				'otherTag'      => $other_tag,
				'expected'      => $expected,
				'filtered'      => $filtered,
				'unfilteredTag' => $unfiltered_tag,
				'noTag'         => $no_tag,
				'seen'          => $seen,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.attributes-dynamic-filter-contract',
			$failures,
			array(
				'tag'      => $tag,
				'caseText' => self::describe_string( $case['text'] ),
			)
		);
	}

	private static function check_rendering_contracts( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$outer       = self::tag( $ctx, 'outer' );
		$inner       = self::tag( $ctx, 'inner' );
		$solo        = self::tag( $ctx, 'solo' );
		$outer_case  = $cases[0];
		$inner_case  = $cases[1];
		$solo_case   = $cases[2];
		$inner_src   = '[' . $inner . ' ' . $inner_case['text'] . ' /]';
		$outer_body  = 'lead ' . $inner_src . ' tail';
		$source      = 'pre [' . $outer . ' ' . $outer_case['text'] . ']' . $outer_body . '[/' . $outer . '] mid [' . $solo . ' ' . $solo_case['text'] . ' /] post';
		$calls       = array();
		$callback    = self::recording_callback( $calls, true );
		$expected    = array(
			array(
				'tag'     => $outer,
				'atts'    => self::expected_atts( $outer_case ),
				'content' => $outer_body,
			),
			array(
				'tag'     => $inner,
				'atts'    => self::expected_atts( $inner_case ),
				'content' => '',
			),
			array(
				'tag'     => $solo,
				'atts'    => self::expected_atts( $solo_case ),
				'content' => '',
			),
		);
		$failures    = array();

		self::replace_registry( array() );
		\add_shortcode( $outer, $callback );
		\add_shortcode( $inner, $callback );
		\add_shortcode( $solo, $callback );

		$output             = \do_shortcode( $source );
		$stripped_output    = \strip_shortcodes( $output );
		$rerendered_output  = \do_shortcode( $output );
		$projected_calls    = self::project_calls( $calls );
		$registered_markers = self::registered_markers( array( $outer, $inner, $solo ) );

		self::collect_failure(
			$failures,
			$projected_calls === $expected,
			'do_shortcode passes generated attributes, raw content, and tag names to callbacks',
			array(
				'expected'   => $expected,
				'actual'     => $projected_calls,
				'difference' => self::first_value_difference( $expected, $projected_calls ),
			)
		);

		self::collect_failure(
			$failures,
			! self::contains_any( $output, $registered_markers )
				&& $stripped_output === $output
				&& $rerendered_output === $output,
			'rendered shortcode output is delimiter-free, strip-stable, and idempotent under do_shortcode',
			array(
				'output'           => self::describe_string( $output ),
				'strippedOutput'   => self::describe_string( $stripped_output ),
				'rerenderedOutput' => self::describe_string( $rerendered_output ),
				'markers'          => $registered_markers,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.rendering-callback-contracts',
			$failures,
			array(
				'tags'       => array( $outer, $inner, $solo ),
				'source'     => self::describe_string( $source ),
				'outputSha1' => sha1( $output ),
				'callCount'  => count( $calls ),
			)
		);
	}

	private static function check_shortcode_tag_filter_contracts( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$pre_tag    = self::tag( $ctx, 'pre-filter' );
		$normal_tag = self::tag( $ctx, 'post-filter' );
		$pre_case   = $cases[1];
		$normal_case = $cases[2];
		$calls      = array();
		$pre_seen   = array();
		$post_seen  = array();
		$callback   = self::recording_callback( $calls, false );
		$pre_value  = 'pre-filtered-' . self::safe_fragment( $ctx->fork( 'pre-value' ), 8, false );
		$source     = 'lead [' . $pre_tag . ' ' . $pre_case['text'] . ']blocked[/' . $pre_tag . '] mid [' . $normal_tag . ' ' . $normal_case['text'] . ' /] tail';
		$pre_filter = static function ( $return, $tag, $attr, $match ) use ( &$pre_seen, $pre_tag, $pre_value ) {
			$pre_seen[] = array(
				'return' => $return,
				'tag'    => $tag,
				'attr'   => self::normalize_atts( is_array( $attr ) ? $attr : array() ),
				'match'  => self::project_shortcode_match( $match ),
			);

			return $pre_tag === $tag ? $pre_value : $return;
		};
		$post_filter = static function ( $output, $tag, $attr, $match ) use ( &$post_seen, $normal_tag ) {
			$post_seen[] = array(
				'output' => $output,
				'tag'    => $tag,
				'attr'   => self::normalize_atts( is_array( $attr ) ? $attr : array() ),
				'match'  => self::project_shortcode_match( $match ),
			);

			return $normal_tag === $tag ? 'post-filtered<' . $output . '>' : $output;
		};
		$failures = array();

		self::replace_registry( array() );
		\add_shortcode( $pre_tag, $callback );
		\add_shortcode( $normal_tag, $callback );
		\add_filter( 'pre_do_shortcode_tag', $pre_filter, 10, 4 );
		\add_filter( 'do_shortcode_tag', $post_filter, 10, 4 );

		try {
			$output = \do_shortcode( $source );
		} finally {
			\remove_filter( 'pre_do_shortcode_tag', $pre_filter, 10 );
			\remove_filter( 'do_shortcode_tag', $post_filter, 10 );
		}

		$normal_marker = 1 === count( $calls ) ? 'post-filtered<cfz-' . $normal_tag . '-' : '';

		self::collect_failure(
			$failures,
			str_contains( $output, $pre_value )
				&& ! str_contains( $output, 'blocked' )
				&& '' !== $normal_marker
				&& str_contains( $output, $normal_marker )
				&& 1 === count( $calls )
				&& $calls[0]['tag'] === $normal_tag
				&& $calls[0]['atts'] === self::expected_atts( $normal_case )
				&& 2 === count( $pre_seen )
				&& 1 === count( $post_seen )
				&& $pre_seen[0]['tag'] === $pre_tag
				&& $pre_seen[0]['return'] === false
				&& $pre_seen[0]['attr'] === self::expected_atts( $pre_case )
				&& $pre_seen[1]['tag'] === $normal_tag
				&& $pre_seen[1]['return'] === false
				&& $pre_seen[1]['attr'] === self::expected_atts( $normal_case )
				&& $post_seen[0]['tag'] === $normal_tag
				&& $post_seen[0]['attr'] === self::expected_atts( $normal_case )
				&& str_starts_with( $post_seen[0]['output'], 'cfz-' . $normal_tag . '-' ),
			'pre_do_shortcode_tag short-circuits one tag while do_shortcode_tag filters callback output for another',
			array(
				'source'   => self::describe_string( $source ),
				'output'   => self::describe_string( $output ),
				'calls'    => self::project_calls( $calls ),
				'preSeen'  => $pre_seen,
				'postSeen' => $post_seen,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.rendering-filter-contracts',
			$failures,
			array(
				'tags'       => array( $pre_tag, $normal_tag ),
				'outputSha1' => sha1( $output ),
			)
		);
	}

	private static function check_escaped_shortcodes( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$tag      = self::tag( $ctx, 'escaped' );
		$case     = $cases[3];
		$calls    = array();
		$callback = self::recording_callback( $calls, false );
		$source   = 'before [[' . $tag . ' ' . $case['text'] . ']] after';
		$expected = 'before [' . $tag . ' ' . $case['text'] . '] after';
		$failures = array();

		self::replace_registry( array() );
		\add_shortcode( $tag, $callback );

		$output = \do_shortcode( $source );

		self::collect_failure(
			$failures,
			$output === $expected && array() === $calls,
			'double-bracket escaped shortcode remains literal and does not invoke the callback',
			array(
				'source'   => self::describe_string( $source ),
				'expected' => self::describe_string( $expected ),
				'actual'   => self::describe_string( $output ),
				'calls'    => $calls,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.escaped-shortcodes-literal',
			$failures,
			array(
				'tag' => $tag,
			)
		);
	}

	private static function check_html_attribute_behavior( \ComponentFuzz\FuzzContext $ctx ): array {
		$tag        = self::tag( $ctx, 'html' );
		$attr_value = self::safe_fragment( $ctx->fork( 'html-attr-value' ), 8, false );
		$attr_text  = 'value=' . $attr_value;
		$expected_atts = array(
			'value' => $attr_value,
		);
		$html_value = 'html-' . self::safe_fragment( $ctx->fork( 'html-value' ), 8, false );
		$calls      = array();
		$callback   = static function ( $atts, $content = '', $shortcode_tag = '' ) use ( &$calls, $html_value ) {
			$calls[] = array(
				'tag'     => (string) $shortcode_tag,
				'atts'    => self::normalize_atts( is_array( $atts ) ? $atts : array() ),
				'content' => (string) $content,
			);

			return $html_value;
		};
		$content    = '<a title="[' . $tag . ' ' . $attr_text . ']" data-other="[missing]">link</a>';
		$failures   = array();

		self::replace_registry( array() );
		\add_shortcode( $tag, $callback );

		$processed_in_html = \do_shortcodes_in_html_tags( $content, false, array( $tag ) );
		$calls_after_html  = $calls;
		$ignored_in_html   = \do_shortcodes_in_html_tags( $content, true, array( $tag ) );
		$calls_after_ignore = $calls;
		$unescaped         = function_exists( 'unescape_invalid_shortcodes' ) ? \unescape_invalid_shortcodes( $ignored_in_html ) : null;
		$full_processed    = \do_shortcode( $content, false );
		$calls_after_full  = $calls;
		$full_ignored      = \do_shortcode( $content, true );
		$calls_after_full_ignore = $calls;

		self::collect_failure(
			$failures,
			1 === count( $calls_after_html )
				&& $calls_after_html[0]['tag'] === $tag
				&& $calls_after_html[0]['atts'] === $expected_atts
				&& str_contains( $processed_in_html, $html_value )
				&& ! str_contains( $processed_in_html, '[' . $tag )
				&& count( $calls_after_ignore ) === count( $calls_after_html )
				&& str_contains( $ignored_in_html, '&#91;' . $tag )
				&& ( null === $unescaped || str_contains( $unescaped, '[' . $tag ) )
				&& str_contains( $full_processed, $html_value )
				&& ! str_contains( $full_processed, '[' . $tag )
				&& count( $calls_after_full ) === count( $calls_after_html ) + 1
				&& count( $calls_after_full_ignore ) === count( $calls_after_full )
				&& str_contains( $full_ignored, '[' . $tag ),
			'HTML attribute shortcode processing honors ignore_html and placeholder unescaping contracts',
			array(
				'content'              => self::describe_string( $content ),
				'processedInHtml'      => self::describe_string( $processed_in_html ),
				'ignoredInHtml'        => self::describe_string( $ignored_in_html ),
				'unescapedIgnoredHtml' => self::describe_string( $unescaped ),
				'fullProcessed'        => self::describe_string( $full_processed ),
				'fullIgnored'          => self::describe_string( $full_ignored ),
				'calls'                => $calls,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.html-attribute-processing',
			$failures,
			array(
				'tag'       => $tag,
				'output'    => $html_value,
				'callCount' => count( $calls ),
			)
		);
	}

	private static function check_strip_and_has_shortcode( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$outer    = self::tag( $ctx, 'strip-outer' );
		$inner    = self::tag( $ctx, 'strip-inner' );
		$absent   = self::tag( $ctx, 'strip-absent' );
		$calls    = array();
		$callback = self::recording_callback( $calls, true );
		$source   = 'start [' . $outer . ' ' . $cases[5]['text'] . ']A [' . $inner . ' ' . $cases[6]['text'] . ' /] B[/' . $outer . '] end';
		$failures = array();

		self::replace_registry( array() );
		\add_shortcode( $outer, $callback );
		\add_shortcode( $inner, $callback );

		$has_outer         = \has_shortcode( $source, $outer );
		$has_inner         = \has_shortcode( $source, $inner );
		$has_absent        = \has_shortcode( $source, $absent );
		$has_without_tags  = \has_shortcode( 'plain text without brackets', $outer );
		$stripped_source   = \strip_shortcodes( $source );
		$rendered          = \do_shortcode( $source );
		$stripped_rendered = \strip_shortcodes( $rendered );
		$rerendered        = \do_shortcode( $rendered );
		$markers           = self::registered_markers( array( $outer, $inner ) );

		self::collect_failure(
			$failures,
			true === $has_outer
				&& true === $has_inner
				&& false === $has_absent
				&& false === $has_without_tags,
			'has_shortcode agrees with generated registered presence and absence',
			array(
				'hasOuter'       => $has_outer,
				'hasInner'       => $has_inner,
				'hasAbsent'      => $has_absent,
				'hasWithoutTags' => $has_without_tags,
			)
		);

		self::collect_failure(
			$failures,
			! self::contains_any( $stripped_source, $markers )
				&& ! self::contains_any( $stripped_rendered, $markers )
				&& $stripped_rendered === $rendered
				&& $rerendered === $rendered,
			'strip_shortcodes removes registered source tags and does not resurrect delimiters after rendering',
			array(
				'source'           => self::describe_string( $source ),
				'strippedSource'   => self::describe_string( $stripped_source ),
				'rendered'         => self::describe_string( $rendered ),
				'strippedRendered' => self::describe_string( $stripped_rendered ),
				'rerendered'       => self::describe_string( $rerendered ),
				'markers'          => $markers,
			)
		);

		return self::result(
			$ctx,
			'shortcodes.strip-and-has-shortcode',
			$failures,
			array(
				'tags'      => array( $outer, $inner ),
				'callCount' => count( $calls ),
			)
		);
	}

	private static function check_tag_discovery_and_apply_alias( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$outer  = self::tag( $ctx, 'discover-outer' );
		$inner  = self::tag( $ctx, 'discover-inner' );
		$solo   = self::tag( $ctx, 'discover-solo' );
		$absent = self::tag( $ctx, 'discover-absent' );
		$calls  = array();
		$callback = self::recording_callback( $calls, true );
		$source = 'start [' . $outer . ' ' . $cases[0]['text'] . ']A [' . $inner . ' ' . $cases[1]['text'] . ' /] B[/' . $outer . '] [' . $solo . ' ' . $cases[2]['text'] . ' /] [missing /] end';
		$failures = array();

		self::replace_registry( array() );
		\add_shortcode( $outer, $callback );
		\add_shortcode( $inner, $callback );
		\add_shortcode( $solo, $callback );

		$tags       = \get_shortcode_tags_in_content( $source );
		$plain_tags = \get_shortcode_tags_in_content( 'plain text without brackets' );
		$has_inner  = \has_shortcode( $source, $inner );
		$has_absent = \has_shortcode( $source, $absent );
		$applied    = \apply_shortcodes( $source );
		$apply_calls = $calls;
		$calls      = array();
		$rendered   = \do_shortcode( $source );
		$do_calls   = $calls;

		self::collect_failure(
			$failures,
			array( $outer, $inner, $solo ) === $tags
				&& array() === $plain_tags
				&& true === $has_inner
				&& false === $has_absent,
			'get_shortcode_tags_in_content reports registered top-level and nested tags in source order',
			array(
				'tags'       => $tags,
				'plainTags'  => $plain_tags,
				'hasInner'   => $has_inner,
				'hasAbsent'  => $has_absent,
				'registered' => array( $outer, $inner, $solo, $absent ),
			)
		);

		self::collect_failure(
			$failures,
			$applied === $rendered
				&& self::project_calls( $apply_calls ) === self::project_calls( $do_calls )
				&& self::call_tags( $apply_calls, $outer ) >= 1
				&& self::call_tags( $apply_calls, $inner ) >= 1
				&& self::call_tags( $apply_calls, $solo ) >= 1,
			'apply_shortcodes is a do_shortcode alias for generated nested content and preserves callback observations',
			array(
				'source'      => self::describe_string( $source ),
				'applied'     => self::describe_string( $applied ),
				'rendered'    => self::describe_string( $rendered ),
				'applyCalls'  => self::project_calls( $apply_calls ),
				'doCalls'     => self::project_calls( $do_calls ),
				'outputDelta' => self::first_value_difference( $rendered, $applied ),
			)
		);

		return self::result(
			$ctx,
			'shortcodes.tag-discovery-and-apply-alias',
			$failures,
			array(
				'tags'       => array( $outer, $inner, $solo ),
				'outputSha1' => sha1( $rendered ),
			)
		);
	}

	private static function check_malformed_and_nested_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$outer       = self::tag( $ctx, 'malformed-outer' );
		$inner       = self::tag( $ctx, 'malformed-inner' );
		$calls       = array();
		$callback    = self::recording_callback( $calls, true );
		$failures    = array();
		$regex       = \get_shortcode_regex( array( $outer, $inner ) );
		$storm       = self::bracket_storm( $ctx->fork( 'storm' ), $outer, $inner );
		$matches     = array();
		$match_count = preg_match_all( '/' . $regex . '/', $storm, $matches, PREG_SET_ORDER );
		$error       = preg_last_error();

		self::replace_registry( array() );
		\add_shortcode( $outer, $callback );
		\add_shortcode( $inner, $callback );

		self::collect_failure(
			$failures,
			false !== $match_count && PREG_NO_ERROR === $error,
			'get_shortcode_regex handles a bounded generated bracket storm without a PCRE error',
			array(
				'storm'         => self::describe_string( $storm ),
				'matchCount'    => $match_count,
				'pregLastError' => $error,
			)
		);

		$malformed_atts = \shortcode_parse_atts( 'good=ok broken="<span" wrapped="<em>x</em>"' );
		self::collect_failure(
			$failures,
			array(
				'good'    => 'ok',
				'broken'  => '',
				'wrapped' => '<em>x</em>',
			) === $malformed_atts,
			'shortcode_parse_atts blanks unclosed HTML attribute values while preserving closed values',
			array(
				'actual' => $malformed_atts,
			)
		);

		$cases = array(
			'broken-html-attribute' => '[' . $outer . ' good=ok broken="<span"]body[/' . $outer . ']',
			'unterminated-quote'    => '[' . $outer . ' good="unterminated body [/' . $outer . ']',
			'lone-open'             => '[' . $outer,
			'lone-close'            => 'prefix [/' . $outer . '] suffix',
			'nested-different-tag'  => '[' . $outer . ']a [' . $inner . ' /] b[/' . $outer . ']',
			'nested-same-tag'       => '[' . $outer . ']same [' . $outer . ' /] close[/' . $outer . ']',
		);
		$case_summaries = array();

		foreach ( $cases as $name => $source ) {
			$rendered = \do_shortcode( $source );
			$stripped = \strip_shortcodes( $source );

			$case_summaries[ $name ] = array(
				'sourceLength'   => strlen( $source ),
				'renderedLength' => strlen( $rendered ),
				'strippedLength' => strlen( $stripped ),
				'renderedSha1'   => sha1( $rendered ),
				'strippedSha1'   => sha1( $stripped ),
			);

			self::collect_failure(
				$failures,
				is_string( $rendered )
					&& is_string( $stripped )
					&& strlen( $rendered ) <= strlen( $source ) + 512
					&& strlen( $stripped ) <= strlen( $source ) + 512,
				"malformed/nested shortcode case {$name} returns bounded string outputs",
				array(
					'name'     => $name,
					'source'   => self::describe_string( $source ),
					'rendered' => self::describe_string( $rendered ),
					'stripped' => self::describe_string( $stripped ),
				)
			);
		}

		self::collect_failure(
			$failures,
			self::call_tags( $calls, $outer ) >= 1 && self::call_tags( $calls, $inner ) >= 1,
			'nested malformed-case corpus exercises both outer and inner callbacks',
			array(
				'calls' => self::project_calls( $calls ),
			)
		);

		return self::result(
			$ctx,
			'shortcodes.malformed-and-nested-bounded',
			$failures,
			array(
				'tags'      => array( $outer, $inner ),
				'cases'     => $case_summaries,
				'callCount' => count( $calls ),
			)
		);
	}

	private static function check_registry_restored( \ComponentFuzz\FuzzContext $ctx, array $snapshot ): array {
		$actual = self::snapshot_registry();
		$ok     = $actual === $snapshot;

		return $ctx->result(
			'shortcodes.registry-restored-exactly',
			$ok,
			array(
				'hadRegistryBefore' => $snapshot['exists'],
				'beforeType'        => get_debug_type( $snapshot['value'] ),
				'afterType'         => get_debug_type( $actual['value'] ),
				'beforeKeys'        => is_array( $snapshot['value'] ) ? array_keys( $snapshot['value'] ) : null,
				'afterKeys'         => is_array( $actual['value'] ) ? array_keys( $actual['value'] ) : null,
			)
		);
	}

	private static function recording_callback( array &$calls, bool $render_nested ): callable {
		return static function ( $atts, $content = '', $shortcode_tag = '' ) use ( &$calls, $render_nested ) {
			$index = count( $calls );
			$calls[ $index ] = array(
				'tag'             => (string) $shortcode_tag,
				'atts'            => self::normalize_atts( is_array( $atts ) ? $atts : array() ),
				'content'         => (string) $content,
				'renderedContent' => null,
			);

			if ( $render_nested && '' !== (string) $content ) {
				$calls[ $index ]['renderedContent'] = \do_shortcode( (string) $content );
			}

			return 'cfz-' . (string) $shortcode_tag . '-' . substr( sha1( self::json( $calls[ $index ] ) ), 0, 12 );
		};
	}

	private static function attribute_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'text'               => 'Alpha="one two" beta-two=\'three four\' gamma_3=five path="a\\\\b" "pos one" \'pos two\' barepos',
				'expectedNamed'      => array(
					'alpha'    => 'one two',
					'beta-two' => 'three four',
					'gamma_3'  => 'five',
					'path'     => 'a\\b',
				),
				'expectedPositional' => array( 'pos one', 'pos two', 'barepos' ),
			),
		);

		for ( $i = 1; $i < self::ATTRIBUTE_CASES; ++$i ) {
			$case_ctx    = $ctx->fork( 'case-' . $i );
			$name_one    = 'Alpha' . $i . self::safe_fragment( $case_ctx->fork( 'name-one' ), 3, false );
			$name_two    = 'beta-' . $i . '-' . self::safe_fragment( $case_ctx->fork( 'name-two' ), 3, false );
			$name_three  = 'gamma_' . $i . '_' . self::safe_fragment( $case_ctx->fork( 'name-three' ), 3, false );
			$value_one   = self::safe_fragment( $case_ctx->fork( 'value-one' ), 5, true );
			$value_two   = self::safe_fragment( $case_ctx->fork( 'value-two' ), 5, true );
			$value_three = self::safe_fragment( $case_ctx->fork( 'value-three' ), 6, false );
			$pos_one     = self::safe_fragment( $case_ctx->fork( 'pos-one' ), 6, true );
			$pos_two     = self::safe_fragment( $case_ctx->fork( 'pos-two' ), 6, true );
			$pos_three   = self::safe_fragment( $case_ctx->fork( 'pos-three' ), 6, false );

			$cases[] = array(
				'text'               => $name_one . '="' . $value_one . '" ' . $name_two . "='" . $value_two . "' " . $name_three . '=' . $value_three . ' "' . $pos_one . '" \'' . $pos_two . '\' ' . $pos_three,
				'expectedNamed'      => array(
					strtolower( $name_one )   => $value_one,
					strtolower( $name_two )   => $value_two,
					strtolower( $name_three ) => $value_three,
				),
				'expectedPositional' => array( $pos_one, $pos_two, $pos_three ),
			);
		}

		return $cases;
	}

	private static function expected_atts( array $case ): array {
		return self::normalize_atts( $case['expectedNamed'] + $case['expectedPositional'] );
	}

	private static function split_attributes( array $atts ): array {
		$named      = array();
		$positional = array();

		foreach ( $atts as $key => $value ) {
			if ( is_int( $key ) ) {
				$positional[] = (string) $value;
			} else {
				$named[ (string) $key ] = (string) $value;
			}
		}

		ksort( $named, SORT_STRING );

		return array(
			'named'      => $named,
			'positional' => $positional,
		);
	}

	private static function normalize_atts( array $atts ): array {
		$split = self::split_attributes( $atts );

		return $split['named'] + $split['positional'];
	}

	private static function project_calls( array $calls ): array {
		$projected = array();

		foreach ( $calls as $call ) {
			$projected[] = array(
				'tag'     => (string) $call['tag'],
				'atts'    => self::normalize_atts( is_array( $call['atts'] ) ? $call['atts'] : array() ),
				'content' => (string) $call['content'],
			);
		}

		return $projected;
	}

	private static function project_shortcode_match( array $match ): array {
		return array(
			'full'       => self::describe_string( $match[0] ?? '' ),
			'escapeOpen' => $match[1] ?? null,
			'tag'        => $match[2] ?? null,
			'attrs'      => self::describe_string( $match[3] ?? '' ),
			'selfClose'  => $match[4] ?? null,
			'content'    => self::describe_string( $match[5] ?? '' ),
			'escapeClose' => $match[6] ?? null,
		);
	}

	private static function result( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		return $ctx->result(
			$invariant,
			array() === $failures,
			$data + array(
				'failureCount' => count( $failures ),
				'failures'     => array_slice( $failures, 0, 6 ),
			)
		);
	}

	private static function collect_failure( array &$failures, bool $ok, string $message, array $data = array() ): void {
		if ( $ok ) {
			return;
		}

		$failures[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private static function snapshot_registry(): array {
		return array(
			'exists' => array_key_exists( 'shortcode_tags', $GLOBALS ),
			'value'  => $GLOBALS['shortcode_tags'] ?? null,
		);
	}

	private static function restore_registry( array $snapshot ): void {
		if ( $snapshot['exists'] ) {
			$GLOBALS['shortcode_tags'] = $snapshot['value'];
		} else {
			unset( $GLOBALS['shortcode_tags'] );
		}
	}

	private static function replace_registry( array $registry ): void {
		$GLOBALS['shortcode_tags'] = $registry;
	}

	private static function tag( \ComponentFuzz\FuzzContext $ctx, string $label ): string {
		return 'cfz_' . str_replace( '-', '_', $label ) . '_' . self::safe_fragment( $ctx->fork( 'tag' ), 8, false );
	}

	private static function safe_fragment( \ComponentFuzz\FuzzContext $ctx, int $max_length, bool $allow_spaces ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$length   = max( 1, $ctx->int( 1, $max_length ) );
		$out      = '';

		for ( $i = 0; $i < $length; ++$i ) {
			if ( $allow_spaces && $i > 0 && $i < $length - 1 && $ctx->bool( 18 ) ) {
				$out .= ' ';
				continue;
			}

			$out .= $alphabet[ $ctx->int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return trim( $out );
	}

	private static function bracket_storm( \ComponentFuzz\FuzzContext $ctx, string $outer, string $inner ): string {
		$pieces = array();
		$count  = $ctx->int( 24, 48 );

		for ( $i = 0; $i < $count; ++$i ) {
			$pieces[] = $ctx->choice(
				array(
					'[',
					']',
					'[[' . $outer . ']]',
					'[' . $outer . ' attr="' . self::safe_fragment( $ctx->fork( 'storm-attr-' . $i ), 6, false ) . '"]',
					'[' . $inner . ' /]',
					'[/' . $outer . ']',
					'plain-' . self::safe_fragment( $ctx->fork( 'storm-text-' . $i ), 8, false ),
				)
			);
		}

		return implode( ' ', $pieces );
	}

	private static function registered_markers( array $tags ): array {
		$markers = array();

		foreach ( $tags as $tag ) {
			$markers[] = '[' . $tag;
			$markers[] = '[/' . $tag . ']';
		}

		return $markers;
	}

	private static function contains_any( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function call_tags( array $calls, string $tag ): int {
		$count = 0;

		foreach ( $calls as $call ) {
			if ( isset( $call['tag'] ) && $call['tag'] === $tag ) {
				++$count;
			}
		}

		return $count;
	}

	private static function first_value_difference( $expected, $actual, string $path = '$' ): ?array {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			return array(
				'path'     => $path,
				'expected' => self::describe_value( $expected ),
				'actual'   => self::describe_value( $actual ),
			);
		}

		if ( is_array( $expected ) ) {
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $actual ) ) {
					return array(
						'path'     => $path . '[' . self::json( $key ) . ']',
						'expected' => self::describe_value( $value ),
						'actual'   => '<missing>',
					);
				}

				$difference = self::first_value_difference( $value, $actual[ $key ], $path . '[' . self::json( $key ) . ']' );
				if ( null !== $difference ) {
					return $difference;
				}
			}

			foreach ( $actual as $key => $value ) {
				if ( ! array_key_exists( $key, $expected ) ) {
					return array(
						'path'     => $path . '[' . self::json( $key ) . ']',
						'expected' => '<missing>',
						'actual'   => self::describe_value( $value ),
					);
				}
			}

			return null;
		}

		if ( $expected !== $actual ) {
			return array(
				'path'     => $path,
				'expected' => self::describe_value( $expected ),
				'actual'   => self::describe_value( $actual ),
			);
		}

		return null;
	}

	private static function describe_value( $value ) {
		if ( is_string( $value ) ) {
			return self::describe_string( $value );
		}

		if ( is_array( $value ) ) {
			return array(
				'type' => 'array',
				'keys' => array_slice( array_keys( $value ), 0, 12 ),
				'size' => count( $value ),
			);
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		return $value;
	}

	private static function describe_string( ?string $value ) {
		if ( null === $value ) {
			return null;
		}

		return array(
			'length'  => strlen( $value ),
			'sha1'    => sha1( $value ),
			'preview' => self::preview( $value ),
		);
	}

	private static function preview( string $value ): string {
		$preview = substr( $value, 0, self::PREVIEW_BYTES );

		if ( strlen( $value ) > self::PREVIEW_BYTES ) {
			$preview .= '...';
		}

		return addcslashes( $preview, "\0..\37\177..\377" );
	}

	private static function describe_throwable( \Throwable $throwable ): array {
		return array(
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => $throwable->getFile(),
			'line'    => $throwable->getLine(),
		);
	}

	private static function json( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
