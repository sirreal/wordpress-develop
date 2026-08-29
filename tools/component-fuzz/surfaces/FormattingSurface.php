<?php
namespace ComponentFuzz\Surfaces;

/**
 * Fuzzes WordPress formatting, text, escaping, and low-level presentation helpers.
 */
final class FormattingSurface {
	public const NAME = 'formatting';

	private const GENERATED_TEXT_CASES = 22;
	private const MAX_INPUT_BYTES      = 2048;
	private const PREVIEW_BYTES        = 180;

	/**
	 * Runs one deterministic formatting fuzz iteration.
	 *
	 * @param \ComponentFuzz\FuzzContext $ctx Fuzzer context supplied by the runner.
	 * @return array<int,array<string,mixed>> Structured invariant rows.
	 */
	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$inputs = self::generate_inputs( $ctx );
		$rows   = array();

		$rows[] = self::check_escaping_contexts( $ctx, $inputs['strings'] );
		$rows[] = self::check_specialchars_round_trips( $ctx, $inputs['roundTripStrings'] );
		$rows[] = self::check_text_sanitizers( $ctx, $inputs['strings'] );
		$rows[] = self::check_normalize_whitespace( $ctx, $inputs['strings'] );
		$rows[] = self::check_wptexturize_rich_text_oracles( $ctx->fork( 'formatting-wptexturize' ), $inputs['texturizeCases'] );
		$rows[] = self::check_autop_shortcode_stability( $ctx, $inputs['autopCases'] );
		$rows[] = self::check_make_clickable( $ctx, $inputs['clickableCases'] );
		$rows[] = self::check_text_link_helpers( $ctx->fork( 'formatting-text-link-helpers' ), $inputs['textLinkCases'] );
		$rows[] = self::check_url_sanitizers( $ctx, $inputs['urlCases'] );
		$rows[] = self::check_deep_mapping_helpers( $ctx->fork( 'formatting-deep-map' ), $inputs['deepCases'] );
		$rows[] = self::check_identifier_sanitizers( $ctx, $inputs['identifierCases'] );
		$rows[] = self::check_file_and_user_sanitizers( $ctx, $inputs['fileNameCases'], $inputs['userNameCases'] );
		$rows[] = self::check_entity_normalization( $ctx, $inputs['entityCases'] );
		$rows[] = self::check_zeroise( $ctx, $inputs['zeroiseCases'] );
		$rows[] = self::check_size_format( $ctx, $inputs['sizeCases'] );
		$rows[] = self::check_human_time_diff( $ctx, $inputs['timeCases'] );
		$rows[] = self::check_hex_colors( $ctx, $inputs['colorCases'] );
		$rows[] = self::check_utf8_and_accents( $ctx, $inputs );

		return $rows;
	}

	private static function generate_inputs( \ComponentFuzz\FuzzContext $ctx ): array {
		$strings = array(
			'',
			'plain ASCII text',
			"quotes ' \" slash \\ amp & angle <tag attr=\"value\">",
			"<script>alert('x')</script><b>bold</b>",
			"line\r\nbreak\n\nnext\tcolumn",
			"percent %00 %2F %e2%82%ac encoded",
			'entities &amp; &lt; &#039; &#x27; &copy; &notit;',
			"shortcode [fmt id=\"1\"]body[/fmt] and [fmt /]",
			"http://example.com/a?b=1&c=<tag> user@example.com",
			"combining e\u{0301} a\u{030A} Hindi \u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}",
			"emoji \u{1F642} snowman \u{2603} cjk \u{4E2D}\u{6587}",
			"invalid bytes \xC0\xAF \xF0\x28\x8C\x28 tail",
			"CDATA <![CDATA[<raw>&\"']]>\n<trail>",
			str_repeat( 'word & <tag> " ', 60 ),
		);

		for ( $i = 0; $i < self::GENERATED_TEXT_CASES; ++$i ) {
			$strings[] = self::generate_text( $ctx->fork( 'formatting-string-' . $i ), self::MAX_INPUT_BYTES );
		}

		$round_trip_strings = array(
			'plain',
			"need escaping <tag> \"single' double\"",
			"Unicode café e\u{0301} \u{2603} \u{4E2D}\u{6587}",
			"tabs\tand\nnewlines",
		);
		for ( $i = 0; $i < 8; ++$i ) {
			$round_trip_strings[] = self::generate_round_trip_text( $ctx->fork( 'formatting-roundtrip-' . $i ) );
		}

		return array(
			'strings'          => array_values( array_map( array( self::class, 'trim_input' ), $strings ) ),
			'roundTripStrings' => $round_trip_strings,
			'texturizeCases'   => self::generate_wptexturize_cases( $ctx->fork( 'formatting-wptexturize' ) ),
			'autopCases'       => self::generate_autop_cases( $ctx->fork( 'formatting-autop' ) ),
			'clickableCases'   => self::generate_clickable_cases( $ctx->fork( 'formatting-clickable' ) ),
			'textLinkCases'    => self::generate_text_link_cases( $ctx->fork( 'formatting-text-link-helpers' ) ),
			'urlCases'         => self::generate_url_cases( $ctx->fork( 'formatting-urls' ) ),
			'deepCases'        => self::generate_deep_map_cases( $ctx->fork( 'formatting-deep-map' ) ),
			'identifierCases'  => self::generate_identifier_cases( $ctx->fork( 'formatting-identifiers' ) ),
			'fileNameCases'    => self::generate_file_name_cases( $ctx->fork( 'formatting-filenames' ) ),
			'userNameCases'    => self::generate_user_name_cases( $ctx->fork( 'formatting-usernames' ) ),
			'entityCases'      => self::generate_entity_cases( $ctx->fork( 'formatting-entities' ) ),
			'zeroiseCases'     => self::generate_zeroise_cases( $ctx->fork( 'formatting-zeroise' ) ),
			'sizeCases'        => self::generate_size_cases( $ctx->fork( 'formatting-size' ) ),
			'timeCases'        => self::generate_time_cases( $ctx->fork( 'formatting-time' ) ),
			'colorCases'       => self::generate_color_cases( $ctx->fork( 'formatting-colors' ) ),
			'utf8Cases'        => self::generate_utf8_cases( $ctx->fork( 'formatting-utf8' ) ),
		);
	}

	private static function check_escaping_contexts( \ComponentFuzz\FuzzContext $ctx, array $strings ): array {
		$functions = array( 'esc_html', 'esc_attr', 'esc_textarea', 'esc_xml' );
		$failures  = array();
		$cases     = 0;

		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$failures[] = self::missing_function_failure( $function );
				continue;
			}

			foreach ( $strings as $case_index => $input ) {
				$call = self::call(
					$function,
					static function () use ( $function, $input ) {
						return $function( $input );
					}
				);
				++$cases;

				if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
					$failures[] = self::call_failure(
						'escaping-call-failed',
						"{$function}() failed or returned a non-string value.",
						$call,
						array(
							'function'  => $function,
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}

				$output     = $call['value'];
				$violations = self::escaped_context_violations( $function, $output );
				$max_length = max( 256, ( strlen( $input ) * 8 ) + 64 );
				if ( strlen( $output ) > $max_length ) {
					$violations[] = array(
						'type'      => 'unexpected-expansion',
						'maxLength' => $max_length,
						'length'    => strlen( $output ),
					);
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'escaping-context-unsafe-output',
						'message'    => "{$function}() left raw delimiter characters for its output context.",
						'function'   => $function,
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'output'     => self::describe_string( $output ),
						'violations' => $violations,
					);
				}
			}
		}

		return self::check_row(
			$ctx,
			'escaping.context_delimiters',
			$failures,
			array(
				'cases'     => $cases,
				'functions' => $functions,
			)
		);
	}

	private static function check_specialchars_round_trips( \ComponentFuzz\FuzzContext $ctx, array $strings ): array {
		$required = array( 'esc_html', 'esc_attr', 'wp_specialchars_decode' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'specialchars_decode.esc_roundtrip', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		$cases    = 0;

		foreach ( $strings as $case_index => $input ) {
			if ( str_contains( $input, '&' ) || ! self::is_valid_utf8( $input ) ) {
				continue;
			}

			foreach ( array( 'esc_html', 'esc_attr' ) as $escape_function ) {
				$escaped = self::call(
					$escape_function,
					static function () use ( $escape_function, $input ) {
						return $escape_function( $input );
					}
				);
				if ( ! $escaped['ok'] || ! is_string( $escaped['value'] ) ) {
					$failures[] = self::call_failure(
						'specialchars-escape-failed',
						"{$escape_function}() failed before round-trip decoding.",
						$escaped,
						array(
							'function'  => $escape_function,
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}

				$decoded = \wp_specialchars_decode( $escaped['value'], ENT_QUOTES );
				++$cases;
				if ( $decoded !== $input ) {
					$failures[] = array(
						'name'       => 'specialchars-roundtrip-mismatch',
						'message'    => 'Escaping then decoding changed a valid entity-free string.',
						'function'   => $escape_function,
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'escaped'    => self::describe_string( $escaped['value'] ),
						'decoded'    => self::describe_string( $decoded ),
						'difference' => self::first_string_difference( $input, $decoded ),
					);
				}
			}
		}

		return self::check_row(
			$ctx,
			'specialchars_decode.esc_roundtrip',
			$failures,
			array( 'cases' => $cases )
		);
	}

	private static function check_text_sanitizers( \ComponentFuzz\FuzzContext $ctx, array $strings ): array {
		$required = array( 'sanitize_text_field', 'sanitize_textarea_field' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'sanitize_text_fields.shape_idempotence', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		$cases    = 0;

		foreach ( $strings as $case_index => $input ) {
			foreach (
				array(
					'sanitize_text_field'     => false,
					'sanitize_textarea_field' => true,
				) as $function => $keeps_newlines
			) {
				$first = self::call(
					$function,
					static function () use ( $function, $input ) {
						return $function( $input );
					}
				);
				++$cases;
				if ( ! $first['ok'] || ! is_string( $first['value'] ) ) {
					$failures[] = self::call_failure(
						'sanitize-text-call-failed',
						"{$function}() failed or returned a non-string value.",
						$first,
						array(
							'function'  => $function,
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}

				$output = $first['value'];
				$again  = self::call(
					$function . ':repeat',
					static function () use ( $function, $output ) {
						return $function( $output );
					}
				);
				if ( ! $again['ok'] || $again['value'] !== $output ) {
					$failures[] = array(
						'name'       => 'sanitize-text-not-idempotent',
						'message'    => "{$function}() changed its own output.",
						'function'   => $function,
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'first'      => self::describe_string( $output ),
						'second'     => self::call_summary( $again ),
						'difference' => $again['ok'] && is_string( $again['value'] ) ? self::first_string_difference( $output, $again['value'] ) : null,
					);
				}

				$shape_violations = array();
				if ( str_contains( $output, '<' ) ) {
					$shape_violations[] = 'raw-less-than';
				}
				if ( preg_match( '/%[a-f0-9]{2}/i', $output ) ) {
					$shape_violations[] = 'percent-encoded-byte';
				}
				if ( ! $keeps_newlines && preg_match( '/[\r\n\t]/', $output ) ) {
					$shape_violations[] = 'line-or-tab-control';
				}

				if ( array() !== $shape_violations ) {
					$failures[] = array(
						'name'       => 'sanitize-text-shape-violation',
						'message'    => "{$function}() returned text with stripped-context syntax still present.",
						'function'   => $function,
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'output'     => self::describe_string( $output ),
						'violations' => $shape_violations,
					);
				}
			}
		}

		return self::check_row(
			$ctx,
			'sanitize_text_fields.shape_idempotence',
			$failures,
			array( 'cases' => $cases )
		);
	}

	private static function check_normalize_whitespace( \ComponentFuzz\FuzzContext $ctx, array $strings ): array {
		if ( ! function_exists( 'normalize_whitespace' ) ) {
			return self::skip_row( $ctx, 'normalize_whitespace.canonical_shape', 'normalize_whitespace() is unavailable.' );
		}

		$failures = array();
		$cases    = 0;

		foreach ( $strings as $case_index => $input ) {
			$first = self::call(
				'normalize_whitespace',
				static function () use ( $input ) {
					return \normalize_whitespace( $input );
				}
			);
			++$cases;
			if ( ! $first['ok'] || ! is_string( $first['value'] ) ) {
				$failures[] = self::call_failure(
					'normalize-whitespace-call-failed',
					'normalize_whitespace() failed or returned a non-string value.',
					$first,
					array(
						'caseIndex' => $case_index,
						'input'     => self::describe_string( $input ),
					)
				);
				continue;
			}

			$output = $first['value'];
			$again  = \normalize_whitespace( $output );
			$shape  = array();
			if ( trim( $output ) !== $output ) {
				$shape[] = 'not-trimmed';
			}
			if ( str_contains( $output, "\r" ) ) {
				$shape[] = 'carriage-return';
			}
			if ( str_contains( $output, "\t" ) ) {
				$shape[] = 'tab';
			}
			if ( str_contains( $output, '  ' ) ) {
				$shape[] = 'repeated-space';
			}
			if ( preg_match( "/\n{2,}/", $output ) ) {
				$shape[] = 'repeated-newline';
			}
			if ( $again !== $output ) {
				$shape[] = 'not-idempotent';
			}

			if ( array() !== $shape ) {
				$failures[] = array(
					'name'       => 'normalize-whitespace-shape-violation',
					'message'    => 'normalize_whitespace() did not return a canonical whitespace shape.',
					'caseIndex'  => $case_index,
					'input'      => self::describe_string( $input ),
					'output'     => self::describe_string( $output ),
					'second'     => self::describe_string( $again ),
					'violations' => $shape,
				);
			}
		}

		return self::check_row(
			$ctx,
			'normalize_whitespace.canonical_shape',
			$failures,
			array( 'cases' => $cases )
		);
	}

	private static function check_wptexturize_rich_text_oracles( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach (
			array(
				'add_filter',
				'add_shortcode',
				'has_filter',
				'remove_filter',
				'wptexturize',
				'wptexturize_primes',
				'_get_wptexturize_split_regex',
				'_get_wptexturize_shortcode_regex',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'wptexturize.rich_text_oracles', "{$function}() is unavailable." );
			}
		}

		global $shortcode_tags;

		$previous_shortcode_tags = $shortcode_tags ?? array();
		$had_cockneyreplace      = array_key_exists( 'wp_cockneyreplace', $GLOBALS );
		$previous_cockneyreplace = $GLOBALS['wp_cockneyreplace'] ?? null;
		$failures                = array();
		$checked                 = 0;
		$tag_filter_calls        = array();
		$shortcode_filter_calls  = array();
		$marker_fragments        = array( '<!--oq-->', '<!--osq-->', '<!--apos-->', '<!--wp-prime-or-quote-->' );

		$tag_filter = static function ( array $tags ) use ( &$tag_filter_calls ): array {
			$tag_filter_calls[] = $tags;
			$tags[]             = 'mark';
			return array_values( array_unique( $tags ) );
		};
		$shortcode_filter = static function ( array $shortcodes ) use ( &$shortcode_filter_calls ): array {
			$shortcode_filter_calls[] = $shortcodes;
			$shortcodes[]             = 'fmtkeep';
			return array_values( array_unique( $shortcodes ) );
		};

		try {
			\add_shortcode(
				'fmttext',
				static function ( $atts = array(), $content = '' ) {
					unset( $atts );
					return (string) $content;
				}
			);
			\add_shortcode(
				'fmtkeep',
				static function ( $atts = array(), $content = '' ) {
					unset( $atts );
					return (string) $content;
				}
			);
			\add_filter( 'no_texturize_tags', $tag_filter, 10, 1 );
			\add_filter( 'no_texturize_shortcodes', $shortcode_filter, 10, 1 );

			\wptexturize( 'component-fuzz-reset', true );

			foreach ( $cases as $case_index => $case ) {
				$first = self::call(
					'wptexturize',
					static function () use ( $case ) {
						return \wptexturize( $case['input'] );
					}
				);
				++$checked;

				if ( ! $first['ok'] || ! is_string( $first['value'] ) ) {
					$failures[] = self::call_failure(
						'wptexturize-call-failed',
						'wptexturize() failed or returned a non-string value.',
						$first,
						array(
							'caseIndex' => $case_index,
							'label'     => $case['label'],
							'input'     => self::describe_string( $case['input'] ),
						)
					);
					continue;
				}

				$output     = $first['value'];
				$again      = self::call(
					'wptexturize:repeat',
					static function () use ( $output ) {
						return \wptexturize( $output );
					}
				);
				$violations = array();

				if ( isset( $case['expected'] ) && $output !== $case['expected'] ) {
					$violations[] = 'exact-output-mismatch';
				}
				if ( ! $again['ok'] || $again['value'] !== $output ) {
					$violations[] = 'not-idempotent';
				}
				if ( strlen( $output ) > max( 512, strlen( $case['input'] ) * 8 + 256 ) ) {
					$violations[] = 'unexpected-expansion';
				}
				foreach ( $case['contains'] ?? array() as $needle ) {
					if ( ! str_contains( $output, $needle ) ) {
						$violations[] = 'missing-fragment:' . $needle;
					}
				}
				foreach ( $case['notContains'] ?? array() as $needle ) {
					if ( str_contains( $output, $needle ) ) {
						$violations[] = 'unexpected-fragment:' . $needle;
					}
				}
				foreach ( $marker_fragments as $marker ) {
					if ( str_contains( $output, $marker ) ) {
						$violations[] = 'internal-marker-leaked:' . $marker;
					}
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'wptexturize-output-oracle-violation',
						'message'    => 'wptexturize() output violated exact, protected-region, marker, or idempotence expectations.',
						'caseIndex'  => $case_index,
						'label'      => $case['label'],
						'input'      => self::describe_string( $case['input'] ),
						'output'     => self::describe_string( $output ),
						'expected'   => isset( $case['expected'] ) ? self::describe_string( $case['expected'] ) : null,
						'second'     => self::call_summary( $again ),
						'violations' => $violations,
						'difference' => isset( $case['expected'] ) ? self::first_string_difference( $case['expected'], $output ) : null,
					);
				}
			}

			$shortcode_regex = \_get_wptexturize_shortcode_regex( array( 'fmttext', 'fmtkeep' ) );
			$split_regex     = \_get_wptexturize_split_regex( $shortcode_regex );
			$split_input     = 'alpha <a href="http://example.test?a=1&b=2">"link"</a> [fmttext attr="<b>"]"short"[/fmttext] <!-- "comment" --> omega';
			$split_parts     = preg_split( $split_regex, $split_input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			$html_regex      = \_get_wptexturize_split_regex();
			$html_parts      = preg_split( $html_regex, $split_input, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

			if (
				! is_array( $split_parts )
				|| implode( '', $split_parts ) !== $split_input
				|| count( $split_parts ) < 7
				|| ! in_array( '[fmttext attr="<b>"]', $split_parts, true )
				|| ! is_array( $html_parts )
				|| implode( '', $html_parts ) !== $split_input
			) {
				$failures[] = array(
					'name'           => 'wptexturize-split-regex-recompose-failed',
					'message'        => 'The texturize HTML/shortcode split regexes did not split and recompose a generated mixed input losslessly.',
					'input'          => self::describe_string( $split_input ),
					'parts'          => is_array( $split_parts ) ? array_map( array( self::class, 'describe_string' ), $split_parts ) : self::describe_value( $split_parts ),
					'htmlParts'      => is_array( $html_parts ) ? array_map( array( self::class, 'describe_string' ), $html_parts ) : self::describe_value( $html_parts ),
					'shortcodeRegex' => self::describe_string( $shortcode_regex ),
				);
			}

			$prime_cases = array(
				array(
					'input'    => "9'",
					'expected' => '9&#8242;',
					'needle'   => "'",
					'prime'    => '&#8242;',
					'open'     => '&#8216;',
					'close'    => '&#8217;',
				),
				array(
					'input'    => '9"',
					'expected' => '9&#8243;',
					'needle'   => '"',
					'prime'    => '&#8243;',
					'open'     => '&#8220;',
					'close'    => '&#8221;',
				),
			);

			foreach ( $prime_cases as $prime_index => $prime_case ) {
				$prime_output = \wptexturize_primes(
					$prime_case['input'],
					$prime_case['needle'],
					$prime_case['prime'],
					$prime_case['open'],
					$prime_case['close']
				);
				if ( $prime_output !== $prime_case['expected'] ) {
					$failures[] = array(
						'name'      => 'wptexturize-primes-simple-oracle-mismatch',
						'message'   => 'wptexturize_primes() did not classify a simple numeric prime fixture as expected.',
						'caseIndex' => $prime_index,
						'input'     => self::describe_string( $prime_case['input'] ),
						'expected'  => self::describe_string( $prime_case['expected'] ),
						'actual'    => self::describe_string( $prime_output ),
					);
				}
			}
		} finally {
			\remove_filter( 'no_texturize_shortcodes', $shortcode_filter, 10 );
			\remove_filter( 'no_texturize_tags', $tag_filter, 10 );
			$shortcode_tags = $previous_shortcode_tags;
			if ( $had_cockneyreplace ) {
				$GLOBALS['wp_cockneyreplace'] = $previous_cockneyreplace;
			} else {
				unset( $GLOBALS['wp_cockneyreplace'] );
			}
			\wptexturize( 'component-fuzz-reset', true );
		}

		if (
			0 === count( $tag_filter_calls )
			|| 0 === count( $shortcode_filter_calls )
			|| false !== \has_filter( 'no_texturize_tags', $tag_filter )
			|| false !== \has_filter( 'no_texturize_shortcodes', $shortcode_filter )
		) {
			$failures[] = array(
				'name'            => 'wptexturize-filter-locality-violation',
				'message'         => 'no_texturize filters were not invoked or were left registered after the texturize check.',
				'tagCalls'        => count( $tag_filter_calls ),
				'shortcodeCalls'  => count( $shortcode_filter_calls ),
				'tagFilter'       => \has_filter( 'no_texturize_tags', $tag_filter ),
				'shortcodeFilter' => \has_filter( 'no_texturize_shortcodes', $shortcode_filter ),
			);
		}

		return self::check_row(
			$ctx,
			'wptexturize.rich_text_oracles',
			$failures,
			array(
				'cases'           => $checked,
				'tagFilterCalls'  => count( $tag_filter_calls ),
				'shortcodeCalls'  => count( $shortcode_filter_calls ),
				'registeredCodes' => array( 'fmttext', 'fmtkeep' ),
			)
		);
	}

	private static function check_autop_shortcode_stability( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach ( array( 'wpautop', 'shortcode_unautop' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'wpautop.shortcode_unautop_stability', "{$function}() is unavailable." );
			}
		}

		global $shortcode_tags;

		$previous_shortcode_tags = $shortcode_tags ?? array();
		if ( function_exists( 'add_shortcode' ) ) {
			\add_shortcode(
				'fmt',
				static function ( $atts = array(), $content = '' ) {
					return (string) $content;
				}
			);
			\add_shortcode(
				'fmt-box',
				static function ( $atts = array(), $content = '' ) {
					return (string) $content;
				}
			);
		}

		$failures = array();
		$checked  = 0;
		try {
			foreach ( $cases as $case_index => $case ) {
				$input = $case['input'];
				$br    = $case['br'];
				$autop = self::call(
					'wpautop',
					static function () use ( $input, $br ) {
						return \wpautop( $input, $br );
					}
				);
				++$checked;

				if ( ! $autop['ok'] || ! is_string( $autop['value'] ) ) {
					$failures[] = self::call_failure(
						'wpautop-call-failed',
						'wpautop() failed or returned a non-string value.',
						$autop,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
							'br'        => $br,
						)
					);
					continue;
				}

				$autop_again = \wpautop( $autop['value'], $br );
				$unautop     = \shortcode_unautop( $autop['value'] );
				$unautop2    = \shortcode_unautop( $unautop );
				$violations  = array();
				if ( $autop_again !== $autop['value'] ) {
					$violations[] = 'wpautop-not-idempotent';
				}
				if ( $unautop2 !== $unautop ) {
					$violations[] = 'shortcode-unautop-not-idempotent';
				}
				if ( preg_match( '/<p>\s*\[(?:fmt|fmt-box)(?![\w-])/i', $unautop ) ) {
					$violations[] = 'standalone-shortcode-still-paragraph-wrapped';
				}
				if ( substr_count( $autop['value'], '<p>' ) !== substr_count( $autop['value'], '</p>' ) ) {
					$violations[] = 'unbalanced-paragraph-tags';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'wpautop-shortcode-stability-violation',
						'message'    => 'wpautop()/shortcode_unautop() changed a balanced generated paragraph shape across repeat application.',
						'caseIndex'  => $case_index,
						'br'         => $br,
						'input'      => self::describe_string( $input ),
						'autop'      => self::describe_string( $autop['value'] ),
						'autopAgain' => self::describe_string( $autop_again ),
						'unautop'    => self::describe_string( $unautop ),
						'violations' => $violations,
					);
				}
			}
		} finally {
			$shortcode_tags = $previous_shortcode_tags;
		}

		return self::check_row(
			$ctx,
			'wpautop.shortcode_unautop_stability',
			$failures,
			array( 'cases' => $checked )
		);
	}

	private static function check_make_clickable( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		if ( ! function_exists( 'make_clickable' ) ) {
			return self::skip_row( $ctx, 'make_clickable.bounded_idempotent_links', 'make_clickable() is unavailable.' );
		}

		$failures    = array();
		$checked     = 0;
		$url_filter  = static function () {
			return 'http://example.test';
		};
		$has_filters = function_exists( 'add_filter' ) && function_exists( 'remove_filter' );

		if ( $has_filters ) {
			\add_filter( 'pre_option_home', $url_filter, 10, 3 );
			\add_filter( 'pre_option_siteurl', $url_filter, 10, 3 );
		}

		try {
			foreach ( $cases as $case_index => $case ) {
				$input = $case['input'];
				$first = self::call(
					'make_clickable',
					static function () use ( $input ) {
						return \make_clickable( $input );
					}
				);
				++$checked;

				if ( ! $first['ok'] || ! is_string( $first['value'] ) ) {
					$failures[] = self::call_failure(
						'make-clickable-call-failed',
						'make_clickable() failed or returned a non-string value.',
						$first,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}

				$output     = $first['value'];
				$second     = self::call(
					'make_clickable:repeat',
					static function () use ( $output ) {
						return \make_clickable( $output );
					}
				);
				$max_length = max( 512, ( strlen( $input ) * 16 ) + 1024 );
				$violations = array();

				if ( ! $second['ok'] || $second['value'] !== $output ) {
					$violations[] = 'not-idempotent';
				}
				if ( strlen( $output ) > $max_length ) {
					$violations[] = 'unexpected-expansion';
				}
				if ( $case['minLinks'] > substr_count( $output, '<a ' ) ) {
					$violations[] = 'expected-link-missing';
				}
				if ( preg_match( '/<a\b[^>]*>(?:(?!<\/a>).)*<a\b/is', $output ) ) {
					$violations[] = 'nested-anchor';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'make-clickable-violation',
						'message'    => 'make_clickable() output was not bounded, stable, or link-complete for a generated plain-text case.',
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'output'     => self::describe_string( $output ),
						'second'     => self::call_summary( $second ),
						'minLinks'   => $case['minLinks'],
						'maxLength'  => $max_length,
						'violations' => $violations,
					);
				}
			}
		} finally {
			if ( $has_filters ) {
				\remove_filter( 'pre_option_home', $url_filter, 10 );
				\remove_filter( 'pre_option_siteurl', $url_filter, 10 );
			}
		}

		return self::check_row(
			$ctx,
			'make_clickable.bounded_idempotent_links',
			$failures,
			array( 'cases' => $checked )
		);
	}

	private static function check_text_link_helpers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach (
			array(
				'add_filter',
				'antispambot',
				'apply_filters',
				'capital_P_dangit',
				'has_filter',
				'remove_filter',
				'wp_html_excerpt',
				'wp_make_link_relative',
				'wp_rel_nofollow',
				'wp_rel_ugc',
				'wp_slash',
				'wp_strip_all_tags',
				'wp_trim_words',
				'wp_unslash',
			) as $function
		) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'text_link_helpers.trim_excerpt_rel_obfuscation_contracts', "{$function}() is unavailable." );
			}
		}

		$failures          = array();
		$checked           = 0;
		$trim_filter_calls = array();
		$home_filter       = static function (): string {
			return 'https://example.test';
		};
		$trim_filter       = static function ( string $trimmed, int $num_words, string $more, string $original_text ) use ( &$trim_filter_calls ): string {
			$trim_filter_calls[] = array(
				'trimmed'  => $trimmed,
				'numWords' => $num_words,
				'more'     => $more,
				'original' => $original_text,
			);

			return $trimmed;
		};

		\add_filter( 'pre_option_home', $home_filter, 10, 3 );
		\add_filter( 'pre_option_siteurl', $home_filter, 10, 3 );
		\add_filter( 'wp_trim_words', $trim_filter, 10, 4 );
		try {
			foreach ( $cases as $case_index => $case ) {
				$violations = array();
				$calls      = array();
				++$checked;

				$trimmed = self::call(
					'wp_trim_words',
					static function () use ( $case ) {
						return \wp_trim_words( $case['trimText'], $case['trimWords'], $case['trimMore'] );
					}
				);
				$calls['trimWords'] = self::call_summary( $trimmed );
				if ( ! $trimmed['ok'] || ! is_string( $trimmed['value'] ) ) {
					$violations[] = 'wp-trim-words-call-failed';
				} else {
					$expected_trimmed = self::expected_wp_trim_words( $case['trimText'], $case['trimWords'], $case['trimMore'] );
					if ( $expected_trimmed !== $trimmed['value'] ) {
						$violations[] = 'wp-trim-words-mismatch';
					}
					if ( preg_match( '/<[^>]*>/', $trimmed['value'] ) ) {
						$violations[] = 'wp-trim-words-kept-html-tag';
					}
					if ( strlen( $trimmed['value'] ) > strlen( \wp_strip_all_tags( $case['trimText'] ) ) + strlen( $case['trimMore'] ) + 4 ) {
						$violations[] = 'wp-trim-words-expanded-unexpectedly';
					}
				}

				$excerpt = self::call(
					'wp_html_excerpt',
					static function () use ( $case ) {
						return \wp_html_excerpt( $case['excerptText'], $case['excerptCount'], $case['excerptMore'] );
					}
				);
				$calls['htmlExcerpt'] = self::call_summary( $excerpt );
				if ( ! $excerpt['ok'] || ! is_string( $excerpt['value'] ) ) {
					$violations[] = 'wp-html-excerpt-call-failed';
				} else {
					$expected_excerpt = self::expected_wp_html_excerpt( $case['excerptText'], $case['excerptCount'], $case['excerptMore'] );
					if ( $expected_excerpt !== $excerpt['value'] ) {
						$violations[] = 'wp-html-excerpt-mismatch';
					}
					if ( preg_match( '/<[^>]*>/', $excerpt['value'] ) || preg_match( '/&[^;\s]{0,6}$/', $excerpt['value'] ) ) {
						$violations[] = 'wp-html-excerpt-unsafe-or-partial-entity';
					}
				}

				foreach ( $case['relativeLinks'] as $link => $expected ) {
					$relative = self::call(
						'wp_make_link_relative',
						static function () use ( $link ) {
							return \wp_make_link_relative( $link );
						}
					);
					$calls['relativeLinks'][ $link ] = self::call_summary( $relative );
					if ( ! $relative['ok'] || $expected !== $relative['value'] ) {
						$violations[] = 'wp-make-link-relative-mismatch';
					}
				}

				$nofollow = self::call(
					'wp_rel_nofollow',
					static function () use ( $case ) {
						return \wp_unslash( \wp_rel_nofollow( \wp_slash( $case['relHtml'] ) ) );
					}
				);
				$ugc      = self::call(
					'wp_rel_ugc',
					static function () use ( $case ) {
						return \wp_unslash( \wp_rel_ugc( \wp_slash( $case['relHtml'] ) ) );
					}
				);
				$ugc_twice = self::call(
					'wp_rel_ugc:repeat',
					static function () use ( $ugc ) {
						return \wp_unslash( \wp_rel_ugc( \wp_slash( is_string( $ugc['value'] ?? null ) ? $ugc['value'] : '' ) ) );
					}
				);
				$calls['nofollow'] = self::call_summary( $nofollow );
				$calls['ugc']      = self::call_summary( $ugc );
				$calls['ugcTwice'] = self::call_summary( $ugc_twice );
				if ( ! $nofollow['ok'] || ! is_string( $nofollow['value'] ) || ! $ugc['ok'] || ! is_string( $ugc['value'] ) || ! $ugc_twice['ok'] || ! is_string( $ugc_twice['value'] ) ) {
					$violations[] = 'rel-helper-call-failed';
				} else {
					$external_nofollow = self::rel_tokens_for_anchor( $nofollow['value'], 'data-link="external"' );
					$internal_nofollow = self::rel_tokens_for_anchor( $nofollow['value'], 'data-link="internal"' );
					$external_ugc      = self::rel_tokens_for_anchor( $ugc['value'], 'data-link="external"' );
					$internal_ugc      = self::rel_tokens_for_anchor( $ugc['value'], 'data-link="internal"' );
					$external_ugc_2    = self::rel_tokens_for_anchor( $ugc_twice['value'], 'data-link="external"' );
					if (
						! self::tokens_include_once( $external_nofollow, array( 'bookmark', 'nofollow' ) )
						|| in_array( 'ugc', $external_nofollow, true )
						|| ! self::tokens_include_once( $internal_nofollow, array( 'bookmark' ) )
						|| in_array( 'nofollow', $internal_nofollow, true )
						|| ! self::tokens_include_once( $external_ugc, array( 'bookmark', 'nofollow', 'ugc' ) )
						|| ! self::tokens_include_once( $internal_ugc, array( 'bookmark', 'ugc' ) )
						|| in_array( 'nofollow', $internal_ugc, true )
						|| $external_ugc !== $external_ugc_2
					) {
						$violations[] = 'rel-helper-token-contract-mismatch';
					}
				}

				foreach ( $case['emails'] as $email ) {
					foreach ( array( 0, 1 ) as $hex_encoding ) {
						$obfuscated = self::call(
							'antispambot',
							static function () use ( $email, $hex_encoding ) {
								return \antispambot( $email, $hex_encoding );
							}
						);
						$calls['antispambot'][] = self::call_summary( $obfuscated );
						if ( ! $obfuscated['ok'] || ! is_string( $obfuscated['value'] ) ) {
							$violations[] = 'antispambot-call-failed';
							continue;
						}

						$decoded = html_entity_decode( rawurldecode( $obfuscated['value'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$has_encoded_at = str_contains( $obfuscated['value'], '&#64;' ) || preg_match( '/%40/i', $obfuscated['value'] );
						if (
							$decoded !== $email
							|| str_contains( $obfuscated['value'], '@' )
							|| ! $has_encoded_at
							|| preg_match( '/[<>]/', $obfuscated['value'] )
							|| strlen( $obfuscated['value'] ) > ( strlen( $email ) * 12 ) + 64
						) {
							$violations[] = 'antispambot-roundtrip-or-shape-mismatch';
						}
					}
				}

				$direct_casing = \capital_P_dangit( $case['wordpressText'] );
				\add_filter( 'the_title', 'capital_P_dangit' );
				try {
					$title_casing = \apply_filters( 'the_title', $case['wordpressText'] );
				} finally {
					\remove_filter( 'the_title', 'capital_P_dangit' );
				}
				$calls['capitalP'] = array(
					'direct' => self::describe_string( $direct_casing ),
					'title'  => self::describe_string( $title_casing ),
				);
				if (
					! str_contains( $direct_casing, ' WordPress' )
					|| ! str_contains( $direct_casing, '>WordPress' )
					|| str_contains( $direct_casing, 'PreWordPress' )
					|| str_contains( $title_casing, 'Wordpress' )
					|| ! str_contains( $title_casing, 'PreWordPress' )
				) {
					$violations[] = 'capital-p-dangit-filter-branch-mismatch';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'text-link-helper-contract-violation',
						'message'    => 'Text/link formatting helpers violated exact trim/excerpt, rel-token, relative URL, obfuscation, or casing contracts.',
						'caseIndex'  => $case_index,
						'caseLabel'  => $case['label'],
						'calls'      => $calls,
						'violations' => array_values( array_unique( $violations ) ),
					);
				}
			}
		} finally {
			\remove_filter( 'wp_trim_words', $trim_filter, 10 );
			\remove_filter( 'pre_option_siteurl', $home_filter, 10 );
			\remove_filter( 'pre_option_home', $home_filter, 10 );
		}

		$filter_payloads_match = count( $trim_filter_calls ) === $checked;
		foreach ( $cases as $index => $case ) {
			if (
				! isset( $trim_filter_calls[ $index ] )
				|| $case['trimWords'] !== $trim_filter_calls[ $index ]['numWords']
				|| $case['trimMore'] !== $trim_filter_calls[ $index ]['more']
				|| $case['trimText'] !== $trim_filter_calls[ $index ]['original']
			) {
				$filter_payloads_match = false;
				break;
			}
		}

		if (
			! $filter_payloads_match
			|| false !== \has_filter( 'wp_trim_words', $trim_filter, 10 )
			|| false !== \has_filter( 'pre_option_home', $home_filter, 10 )
			|| false !== \has_filter( 'pre_option_siteurl', $home_filter, 10 )
			|| false !== \has_filter( 'the_title', 'capital_P_dangit' )
		) {
			$failures[] = array(
				'name'         => 'text-link-helper-filter-lifecycle-violation',
				'message'      => 'Text/link helper filters did not observe exact payloads or were not removed.',
				'trimCalls'    => array_slice( $trim_filter_calls, 0, 5 ),
				'expected'     => $checked,
				'activeFilter' => array(
					'trim'    => \has_filter( 'wp_trim_words', $trim_filter, 10 ),
					'home'    => \has_filter( 'pre_option_home', $home_filter, 10 ),
					'siteurl' => \has_filter( 'pre_option_siteurl', $home_filter, 10 ),
					'title'   => \has_filter( 'the_title', 'capital_P_dangit' ),
				),
			);
		}

		return self::check_row(
			$ctx,
			'text_link_helpers.trim_excerpt_rel_obfuscation_contracts',
			$failures,
			array(
				'cases'          => $checked,
				'trimFilterCalls' => count( $trim_filter_calls ),
			)
		);
	}

	private static function check_url_sanitizers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach ( array( 'esc_url', 'sanitize_url' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'url_sanitizers.display_storage_contracts', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		$checked  = 0;

		foreach ( $cases as $case_index => $case ) {
			$input       = $case['input'];
			$display     = self::call(
				'esc_url',
				static function () use ( $input ) {
					return \esc_url( $input );
				}
			);
			$storage     = self::call(
				'sanitize_url',
				static function () use ( $input ) {
					return \sanitize_url( $input );
				}
			);
			$storage_raw = self::call(
				'esc_url:db',
				static function () use ( $input ) {
					return \esc_url( $input, null, 'db' );
				}
			);
			++$checked;

			if ( ! $display['ok'] || ! is_string( $display['value'] ) || ! $storage['ok'] || ! is_string( $storage['value'] ) || ! $storage_raw['ok'] || ! is_string( $storage_raw['value'] ) ) {
				$failures[] = array(
					'name'      => 'url-sanitizer-call-failed',
					'message'   => 'URL sanitizer returned a non-string value or threw.',
					'caseIndex' => $case_index,
					'input'     => self::describe_string( $input ),
					'display'   => self::call_summary( $display ),
					'storage'   => self::call_summary( $storage ),
					'raw'       => self::call_summary( $storage_raw ),
				);
				continue;
			}

			$violations = array();
			if ( $storage['value'] !== $storage_raw['value'] ) {
				$violations[] = 'sanitize-url-esc-url-db-disagreement';
			}
			if ( \sanitize_url( $storage['value'] ) !== $storage['value'] ) {
				$violations[] = 'sanitize-url-not-idempotent';
			}
			if ( preg_match( '/[\x00-\x1F\x7F<>"\']/', $display['value'] . $storage['value'] ) ) {
				$violations[] = 'unsafe-control-or-html-delimiter';
			}
			if ( $case['disallowedProtocol'] && ( '' !== $display['value'] || '' !== $storage['value'] ) ) {
				$violations[] = 'disallowed-protocol-not-rejected';
			}
			if ( ! $case['disallowedProtocol'] && '' !== $storage['value'] && ! self::url_has_allowed_shape( $storage['value'] ) ) {
				$violations[] = 'unexpected-storage-url-shape';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'url-sanitizer-contract-violation',
					'message'    => 'esc_url()/sanitize_url() did not preserve display/storage URL contracts for a generated URL case.',
					'caseIndex'  => $case_index,
					'case'       => $case,
					'input'      => self::describe_string( $input ),
					'display'    => self::describe_string( $display['value'] ),
					'storage'    => self::describe_string( $storage['value'] ),
					'storageRaw' => self::describe_string( $storage_raw['value'] ),
					'violations' => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'url_sanitizers.display_storage_contracts',
			$failures,
			array( 'cases' => $checked )
		);
	}

	private static function check_deep_mapping_helpers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		$required = array( 'map_deep', 'urlencode_deep', 'rawurlencode_deep', 'urldecode_deep', 'stripslashes_deep', 'wp_slash', 'wp_unslash' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'deep_mapping_helpers.shape_roundtrip_contracts', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		$checked  = 0;
		$leaves   = 0;

		foreach ( $cases as $case_index => $case ) {
			$value       = $case['value'];
			$slash_value = $case['slashValue'];
			$leaf_count  = self::deep_leaf_count( $value );
			$shape       = self::deep_shape_signature( $value );
			$violations  = array();
			$calls       = array();
			++$checked;
			$leaves += $leaf_count;

			$seen       = 0;
			$mapped     = self::call(
				'map_deep:marker',
				static function () use ( $value, &$seen ) {
					return \map_deep(
						self::deep_clone( $value ),
						static function ( $leaf ) use ( &$seen ) {
							++$seen;
							return self::deep_map_marker( $leaf );
						}
					);
				}
			);
			$calls['mapDeep'] = self::call_summary( $mapped );
			if ( ! $mapped['ok'] ) {
				$violations[] = 'map-deep-threw';
			} else {
				$expected_mapped = self::deep_transform(
					$value,
					static function ( $leaf ) {
						return self::deep_map_marker( $leaf );
					}
				);
				if ( $seen !== $leaf_count ) {
					$violations[] = 'map-deep-leaf-count-mismatch';
				}
				if ( self::deep_shape_signature( $mapped['value'] ) !== $shape ) {
					$violations[] = 'map-deep-shape-changed';
				}
				if ( ! self::deep_same( $mapped['value'], $expected_mapped ) ) {
					$violations[] = 'map-deep-transform-mismatch';
				}
			}

			$urlencoded = self::call(
				'urlencode_deep',
				static function () use ( $value ) {
					return \urlencode_deep( self::deep_clone( $value ) );
				}
			);
			$calls['urlencodeDeep'] = self::call_summary( $urlencoded );
			if ( ! $urlencoded['ok'] ) {
				$violations[] = 'urlencode-deep-threw';
			} else {
				$expected_urlencoded = self::deep_transform(
					$value,
					static function ( $leaf ): string {
						return urlencode( $leaf );
					}
				);
				if ( self::deep_shape_signature( $urlencoded['value'] ) !== $shape ) {
					$violations[] = 'urlencode-deep-shape-changed';
				}
				if ( ! self::deep_same( $urlencoded['value'], $expected_urlencoded ) ) {
					$violations[] = 'urlencode-deep-transform-mismatch';
				}
			}

			$url_roundtrip = self::call(
				'urldecode_deep:urlencode_deep',
				static function () use ( $value ) {
					return \urldecode_deep( \urlencode_deep( self::deep_clone( $value ) ) );
				}
			);
			$calls['urlRoundtrip'] = self::call_summary( $url_roundtrip );
			if ( ! $url_roundtrip['ok'] ) {
				$violations[] = 'urlencode-urldecode-roundtrip-threw';
			} else {
				$expected_decoded = self::deep_transform(
					$value,
					static function ( $leaf ): string {
						return urldecode( urlencode( $leaf ) );
					}
				);
				if ( self::deep_shape_signature( $url_roundtrip['value'] ) !== $shape ) {
					$violations[] = 'urlencode-urldecode-shape-changed';
				}
				if ( ! self::deep_same( $url_roundtrip['value'], $expected_decoded ) ) {
					$violations[] = 'urlencode-urldecode-roundtrip-mismatch';
				}
			}

			$rawurlencoded = self::call(
				'rawurlencode_deep',
				static function () use ( $value ) {
					return \rawurlencode_deep( self::deep_clone( $value ) );
				}
			);
			$calls['rawurlencodeDeep'] = self::call_summary( $rawurlencoded );
			if ( ! $rawurlencoded['ok'] ) {
				$violations[] = 'rawurlencode-deep-threw';
			} else {
				$expected_rawurlencoded = self::deep_transform(
					$value,
					static function ( $leaf ): string {
						return rawurlencode( $leaf );
					}
				);
				if ( self::deep_shape_signature( $rawurlencoded['value'] ) !== $shape ) {
					$violations[] = 'rawurlencode-deep-shape-changed';
				}
				if ( ! self::deep_same( $rawurlencoded['value'], $expected_rawurlencoded ) ) {
					$violations[] = 'rawurlencode-deep-transform-mismatch';
				}
			}

			$stripped = self::call(
				'stripslashes_deep',
				static function () use ( $value ) {
					return \stripslashes_deep( self::deep_clone( $value ) );
				}
			);
			$calls['stripslashesDeep'] = self::call_summary( $stripped );
			if ( ! $stripped['ok'] ) {
				$violations[] = 'stripslashes-deep-threw';
			} else {
				$expected_stripped = self::deep_transform(
					$value,
					static function ( $leaf ) {
						return is_string( $leaf ) ? stripslashes( $leaf ) : $leaf;
					}
				);
				if ( self::deep_shape_signature( $stripped['value'] ) !== $shape ) {
					$violations[] = 'stripslashes-deep-shape-changed';
				}
				if ( ! self::deep_same( $stripped['value'], $expected_stripped ) ) {
					$violations[] = 'stripslashes-deep-transform-mismatch';
				}
			}

			$slash_roundtrip = self::call(
				'wp_unslash:wp_slash',
				static function () use ( $slash_value ) {
					return \wp_unslash( \wp_slash( self::deep_clone( $slash_value ) ) );
				}
			);
			$calls['slashRoundtrip'] = self::call_summary( $slash_roundtrip );
			if ( ! $slash_roundtrip['ok'] ) {
				$violations[] = 'wp-slash-roundtrip-threw';
			} elseif ( ! self::deep_same( $slash_roundtrip['value'], $slash_value ) ) {
				$violations[] = 'wp-slash-roundtrip-mismatch';
			}

			foreach (
				array(
					'mapDeep'           => $mapped,
					'urlencodeDeep'     => $urlencoded,
					'urlRoundtrip'      => $url_roundtrip,
					'rawurlencodeDeep'  => $rawurlencoded,
					'stripslashesDeep'  => $stripped,
					'slashRoundtrip'    => $slash_roundtrip,
				) as $label => $call
			) {
				if ( ! $call['ok'] ) {
					continue;
				}
				$max_bytes = max( 768, self::deep_serialized_bytes( 'slashRoundtrip' === $label ? $slash_value : $value ) * 8 + 256 );
				if ( self::deep_serialized_bytes( $call['value'] ) > $max_bytes ) {
					$violations[] = $label . '-unexpected-expansion';
				}
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'          => 'deep-mapping-helper-contract-violation',
					'message'       => 'Deep formatting helpers changed structure, missed leaves, failed helper-specific transforms, or expanded unexpectedly.',
					'caseIndex'     => $case_index,
					'label'         => $case['label'],
					'input'         => self::deep_summary( $value ),
					'slashInput'    => self::deep_summary( $slash_value ),
					'leafCount'     => $leaf_count,
					'mapSeenLeaves' => $seen,
					'calls'         => $calls,
					'violations'    => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'deep_mapping_helpers.shape_roundtrip_contracts',
			$failures,
			array(
				'cases'  => $checked,
				'leaves' => $leaves,
			)
		);
	}

	private static function check_identifier_sanitizers( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach ( array( 'sanitize_html_class', 'sanitize_key', 'sanitize_title', 'sanitize_title_with_dashes' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'identifier_sanitizers.shape_filter_contracts', "{$function}() is unavailable." );
			}
		}

		$failures         = array();
		$title_calls      = array();
		$key_calls        = array();
		$html_class_calls = array();

		$title_filter = static function ( string $title, string $raw_title, string $context ) use ( &$title_calls ): string {
			$title_calls[] = array(
				'title'   => $title,
				'raw'     => $raw_title,
				'context' => $context,
			);

			return $title;
		};
		$key_filter = static function ( string $sanitized, $raw_key ) use ( &$key_calls ): string {
			$key_calls[] = array(
				'sanitized' => $sanitized,
				'raw'       => $raw_key,
			);

			return $sanitized;
		};
		$html_class_filter = static function ( string $sanitized, string $raw_class, string $fallback ) use ( &$html_class_calls ): string {
			$html_class_calls[] = array(
				'sanitized' => $sanitized,
				'raw'       => $raw_class,
				'fallback'  => $fallback,
			);

			return $sanitized;
		};

		\add_filter( 'sanitize_title', $title_filter, 99, 3 );
		\add_filter( 'sanitize_key', $key_filter, 10, 2 );
		\add_filter( 'sanitize_html_class', $html_class_filter, 10, 3 );

		try {
			foreach ( $cases as $case_index => $case ) {
				$input    = (string) $case['input'];
				$fallback = (string) $case['fallback'];

				$key        = \sanitize_key( $input );
				$key_again  = \sanitize_key( $key );
				$class      = \sanitize_html_class( $input );
				$fallbacked = \sanitize_html_class( $input, $fallback );
				$slug       = \sanitize_title_with_dashes( $input, '', 'save' );
				$slug_again = \sanitize_title_with_dashes( $slug, '', 'save' );
				$title      = \sanitize_title( $input, $fallback, 'save' );

				$violations = array();
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_-]*$/', $key ) ) {
					$violations[] = 'sanitize-key-shape';
				}
				if ( $key_again !== $key ) {
					$violations[] = 'sanitize-key-not-idempotent';
				}
				if ( ! is_string( $class ) || ! preg_match( '/^[A-Za-z0-9_-]*$/', $class ) ) {
					$violations[] = 'sanitize-html-class-shape';
				}
				if ( str_contains( $class, '%' ) || $class !== \sanitize_html_class( $class ) ) {
					$violations[] = 'sanitize-html-class-not-canonical';
				}
				if ( '' === $class && \sanitize_html_class( $fallback ) !== $fallbacked ) {
					$violations[] = 'sanitize-html-class-fallback-mismatch';
				}
				if ( ! is_string( $slug ) || ! preg_match( '/^(?:[a-z0-9_-]|%[a-f0-9]{2})*$/', $slug ) ) {
					$violations[] = 'sanitize-title-dashes-shape';
				}
				if ( '' !== $slug && ( trim( $slug, '-' ) !== $slug || str_contains( $slug, '--' ) ) ) {
					$violations[] = 'sanitize-title-dashes-not-trimmed';
				}
				if ( $slug_again !== $slug ) {
					$violations[] = 'sanitize-title-dashes-not-idempotent';
				}
				if ( ! is_string( $title ) || '' === $title ) {
					$violations[] = 'sanitize-title-empty-with-fallback';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'identifier-sanitizer-contract-violation',
						'message'    => 'Identifier sanitizers returned a non-canonical shape, lost fallback behavior, or were not idempotent.',
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'fallback'   => $fallback,
						'key'        => self::describe_string( is_string( $key ) ? $key : '' ),
						'class'      => self::describe_string( is_string( $class ) ? $class : '' ),
						'fallbacked' => self::describe_string( is_string( $fallbacked ) ? $fallbacked : '' ),
						'slug'       => self::describe_string( is_string( $slug ) ? $slug : '' ),
						'title'      => self::describe_string( is_string( $title ) ? $title : '' ),
						'violations' => $violations,
					);
				}
			}
		} finally {
			\remove_filter( 'sanitize_title', $title_filter, 99 );
			\remove_filter( 'sanitize_key', $key_filter, 10 );
			\remove_filter( 'sanitize_html_class', $html_class_filter, 10 );
		}

		$title_by_raw = array();
		foreach ( $title_calls as $call ) {
			$title_by_raw[ $call['raw'] . "\0" . $call['context'] ] = true;
		}

		foreach ( $cases as $case_index => $case ) {
			$key = (string) $case['input'] . "\0save";
			if ( empty( $title_by_raw[ $key ] ) ) {
				$failures[] = array(
					'name'      => 'sanitize-title-filter-missing',
					'message'   => 'sanitize_title filter did not observe the generated raw title/context pair.',
					'caseIndex' => $case_index,
					'input'     => self::describe_string( (string) $case['input'] ),
				);
			}
		}

		if (
			count( $key_calls ) < count( $cases ) * 2
			|| count( $html_class_calls ) < count( $cases ) * 2
			|| false !== \has_filter( 'sanitize_title', $title_filter, 99 )
			|| false !== \has_filter( 'sanitize_key', $key_filter, 10 )
			|| false !== \has_filter( 'sanitize_html_class', $html_class_filter, 10 )
		) {
			$failures[] = array(
				'name'    => 'identifier-sanitizer-filter-contract',
				'message' => 'Identifier sanitizer filters did not receive expected calls or were not removed.',
				'counts'  => array(
					'title'     => count( $title_calls ),
					'key'       => count( $key_calls ),
					'htmlClass' => count( $html_class_calls ),
				),
				'active'  => array(
					'title'     => \has_filter( 'sanitize_title', $title_filter, 99 ),
					'key'       => \has_filter( 'sanitize_key', $key_filter, 10 ),
					'htmlClass' => \has_filter( 'sanitize_html_class', $html_class_filter, 10 ),
				),
			);
		}

		return self::check_row(
			$ctx,
			'identifier_sanitizers.shape_filter_contracts',
			$failures,
			array(
				'cases'       => count( $cases ),
				'filterCalls' => array(
					'title'     => count( $title_calls ),
					'key'       => count( $key_calls ),
					'htmlClass' => count( $html_class_calls ),
				),
			)
		);
	}

	private static function check_file_and_user_sanitizers( \ComponentFuzz\FuzzContext $ctx, array $file_cases, array $user_cases ): array {
		foreach ( array( 'sanitize_file_name', 'sanitize_user' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'file_user_sanitizers.shape_filter_contracts', "{$function}() is unavailable." );
			}
		}

		$failures             = array();
		$file_filter_calls    = array();
		$file_chars_calls     = array();
		$user_filter_calls    = array();
		$file_filter_expected = array();
		$user_filter_expected = array();

		$file_chars_filter = static function ( array $special_chars, string $filename_raw ) use ( &$file_chars_calls ): array {
			$file_chars_calls[] = array(
				'raw'   => $filename_raw,
				'count' => count( $special_chars ),
			);

			return $special_chars;
		};
		$file_filter       = static function ( string $filename, string $filename_raw ) use ( &$file_filter_calls ): string {
			$file_filter_calls[] = array(
				'sanitized' => $filename,
				'raw'       => $filename_raw,
			);

			return $filename;
		};
		$user_filter       = static function ( string $username, string $raw_username, bool $strict ) use ( &$user_filter_calls ): string {
			$user_filter_calls[] = array(
				'sanitized' => $username,
				'raw'       => $raw_username,
				'strict'    => $strict,
			);

			return $username;
		};

		\add_filter( 'sanitize_file_name_chars', $file_chars_filter, 10, 2 );
		\add_filter( 'sanitize_file_name', $file_filter, 10, 2 );
		\add_filter( 'sanitize_user', $user_filter, 10, 3 );

		try {
			foreach ( $file_cases as $case_index => $case ) {
				$input     = $case['input'];
				$sanitized = self::call(
					'sanitize_file_name',
					static function () use ( $input ) {
						return \sanitize_file_name( $input );
					}
				);

				if ( ! $sanitized['ok'] || ! is_string( $sanitized['value'] ) ) {
					$failures[] = self::call_failure(
						'sanitize-file-name-call-failed',
						'sanitize_file_name() failed or returned a non-string value.',
						$sanitized,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}

				$file_filter_expected[] = array(
					'raw'       => $input,
					'sanitized' => $sanitized['value'],
				);

				$again      = \sanitize_file_name( $sanitized['value'] );
				$violations = self::file_name_violations( $sanitized['value'], $again );
				if ( isset( $case['expected'] ) && $sanitized['value'] !== $case['expected'] ) {
					$violations[] = 'known-fixture-mismatch';
				}
				if ( ! empty( $case['expectIntermediatePhpUnderscore'] ) && ! preg_match( '/\\.p(?:hp|html)_\\.[^.]+$/i', $sanitized['value'] ) ) {
					$violations[] = 'intermediate-php-extension-not-suffixed';
				}
				if ( ! empty( $case['expectUnnamedFile'] ) && ! str_starts_with( $sanitized['value'], 'unnamed-file.' ) ) {
					$violations[] = 'unnamed-extension-not-replaced';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'sanitize-file-name-contract-violation',
						'message'    => 'sanitize_file_name() returned an unsafe, non-canonical, or unexpected filename.',
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'expected'   => $case['expected'] ?? null,
						'sanitized'  => self::describe_string( $sanitized['value'] ),
						'again'      => self::describe_string( $again ),
						'violations' => $violations,
					);
				}
			}

			foreach ( $user_cases as $case_index => $case ) {
				$input        = $case['input'];
				$loose        = self::call(
					'sanitize_user',
					static function () use ( $input ) {
						return \sanitize_user( $input, false );
					}
				);
				$strict       = self::call(
					'sanitize_user:strict',
					static function () use ( $input ) {
						return \sanitize_user( $input, true );
					}
				);
				$expected_raw = array(
					array( 'raw' => $input, 'strict' => false ),
					array( 'raw' => $input, 'strict' => true ),
				);

				if ( ! $loose['ok'] || ! is_string( $loose['value'] ) || ! $strict['ok'] || ! is_string( $strict['value'] ) ) {
					$failures[] = array(
						'name'      => 'sanitize-user-call-failed',
						'message'   => 'sanitize_user() failed or returned a non-string value.',
						'caseIndex' => $case_index,
						'input'     => self::describe_string( $input ),
						'loose'     => self::call_summary( $loose ),
						'strict'    => self::call_summary( $strict ),
					);
					continue;
				}

				foreach ( $expected_raw as $expected ) {
					$user_filter_expected[] = $expected;
				}

				$loose_again  = \sanitize_user( $loose['value'], false );
				$strict_again = \sanitize_user( $strict['value'], true );
				$violations   = self::user_name_violations( $loose['value'], $strict['value'], $loose_again, $strict_again );
				if ( isset( $case['expectedLoose'] ) && $loose['value'] !== $case['expectedLoose'] ) {
					$violations[] = 'known-loose-fixture-mismatch';
				}
				if ( isset( $case['expectedStrict'] ) && $strict['value'] !== $case['expectedStrict'] ) {
					$violations[] = 'known-strict-fixture-mismatch';
				}

				if ( array() !== $violations ) {
					$failures[] = array(
						'name'        => 'sanitize-user-contract-violation',
						'message'     => 'sanitize_user() returned unsafe text, failed strict-mode reduction, or was not idempotent.',
						'caseIndex'   => $case_index,
						'input'       => self::describe_string( $input ),
						'loose'       => self::describe_string( $loose['value'] ),
						'strict'      => self::describe_string( $strict['value'] ),
						'looseAgain'  => self::describe_string( $loose_again ),
						'strictAgain' => self::describe_string( $strict_again ),
						'violations'  => $violations,
					);
				}
			}
		} finally {
			\remove_filter( 'sanitize_file_name_chars', $file_chars_filter, 10 );
			\remove_filter( 'sanitize_file_name', $file_filter, 10 );
			\remove_filter( 'sanitize_user', $user_filter, 10 );
		}

		foreach ( $file_filter_expected as $expected ) {
			if ( ! self::has_file_filter_call( $file_filter_calls, $expected['raw'], $expected['sanitized'] ) ) {
				$failures[] = array(
					'name'      => 'sanitize-file-name-filter-missing',
					'message'   => 'sanitize_file_name filter did not observe the generated raw/sanitized filename pair.',
					'raw'       => self::describe_string( $expected['raw'] ),
					'sanitized' => self::describe_string( $expected['sanitized'] ),
				);
			}
		}

		foreach ( $user_filter_expected as $expected ) {
			if ( ! self::has_user_filter_call( $user_filter_calls, $expected['raw'], $expected['strict'] ) ) {
				$failures[] = array(
					'name'    => 'sanitize-user-filter-missing',
					'message' => 'sanitize_user filter did not observe the generated raw username and strict flag.',
					'raw'     => self::describe_string( $expected['raw'] ),
					'strict'  => $expected['strict'],
				);
			}
		}

		if (
			count( $file_chars_calls ) < count( $file_cases )
			|| count( $file_filter_calls ) < count( $file_cases )
			|| count( $user_filter_calls ) < count( $user_cases ) * 2
			|| false !== \has_filter( 'sanitize_file_name_chars', $file_chars_filter, 10 )
			|| false !== \has_filter( 'sanitize_file_name', $file_filter, 10 )
			|| false !== \has_filter( 'sanitize_user', $user_filter, 10 )
		) {
			$failures[] = array(
				'name'    => 'file-user-sanitizer-filter-contract',
				'message' => 'File/user sanitizer filters did not receive expected calls or were not removed.',
				'counts'  => array(
					'fileChars' => count( $file_chars_calls ),
					'file'      => count( $file_filter_calls ),
					'user'      => count( $user_filter_calls ),
				),
				'active'  => array(
					'fileChars' => \has_filter( 'sanitize_file_name_chars', $file_chars_filter, 10 ),
					'file'      => \has_filter( 'sanitize_file_name', $file_filter, 10 ),
					'user'      => \has_filter( 'sanitize_user', $user_filter, 10 ),
				),
			);
		}

		return self::check_row(
			$ctx,
			'file_user_sanitizers.shape_filter_contracts',
			$failures,
			array(
				'fileCases'   => count( $file_cases ),
				'userCases'   => count( $user_cases ),
				'filterCalls' => array(
					'fileChars' => count( $file_chars_calls ),
					'file'      => count( $file_filter_calls ),
					'user'      => count( $user_filter_calls ),
				),
			)
		);
	}

	private static function check_entity_normalization( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach ( array( 'convert_chars', 'ent2ncr' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'entities.normalization_idempotence', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		$checked  = 0;

		foreach ( $cases as $case_index => $input ) {
			$converted = self::call(
				'convert_chars',
				static function () use ( $input ) {
					return \convert_chars( $input );
				}
			);
			$ncr       = self::call(
				'ent2ncr',
				static function () use ( $input ) {
					return \ent2ncr( $input );
				}
			);
			++$checked;

			if ( ! $converted['ok'] || ! is_string( $converted['value'] ) || ! $ncr['ok'] || ! is_string( $ncr['value'] ) ) {
				$failures[] = array(
					'name'         => 'entity-normalizer-call-failed',
					'message'      => 'convert_chars() or ent2ncr() failed or returned a non-string value.',
					'caseIndex'    => $case_index,
					'input'        => self::describe_string( $input ),
					'convertChars' => self::call_summary( $converted ),
					'ent2ncr'      => self::call_summary( $ncr ),
				);
				continue;
			}

			$converted_again = \convert_chars( $converted['value'] );
			$converted_third = \convert_chars( $converted_again );
			$ncr_again       = \ent2ncr( $ncr['value'] );
			$violations      = array();
			if ( $converted_third !== $converted_again ) {
				$violations[] = 'convert-chars-not-convergent';
			}
			if ( preg_match( '/&([^#])(?![a-z1-4]{1,8};)/i', $converted_again ) ) {
				$violations[] = 'convert-chars-left-lone-ampersand';
			}
			if ( $ncr_again !== $ncr['value'] ) {
				$violations[] = 'ent2ncr-not-idempotent';
			}
			foreach ( self::known_ncr_entities() as $entity ) {
				if ( str_contains( $input, $entity ) && str_contains( $ncr['value'], $entity ) ) {
					$violations[] = 'ent2ncr-left-known-named-entity';
					break;
				}
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'           => 'entity-normalization-violation',
					'message'        => 'Entity normalization was not stable or left a known generated entity unnormalized.',
					'caseIndex'      => $case_index,
					'input'          => self::describe_string( $input ),
					'convertChars'   => self::describe_string( $converted['value'] ),
					'convertAgain'   => self::describe_string( $converted_again ),
					'convertThird'   => self::describe_string( $converted_third ),
					'ent2ncr'        => self::describe_string( $ncr['value'] ),
					'ent2ncrAgain'   => self::describe_string( $ncr_again ),
					'violations'     => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'entities.normalization_idempotence',
			$failures,
			array( 'cases' => $checked )
		);
	}

	private static function check_zeroise( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		if ( ! function_exists( 'zeroise' ) ) {
			return self::skip_row( $ctx, 'zeroise.length_sign_contract', 'zeroise() is unavailable.' );
		}

		$failures = array();

		foreach ( $cases as $case_index => $case ) {
			$number    = $case['number'];
			$threshold = $case['threshold'];
			$result    = self::call(
				'zeroise',
				static function () use ( $number, $threshold ) {
					return \zeroise( $number, $threshold );
				}
			);

			if ( ! $result['ok'] || ! is_string( $result['value'] ) ) {
				$failures[] = self::call_failure(
					'zeroise-call-failed',
					'zeroise() failed or returned a non-string value.',
					$result,
					array(
						'caseIndex' => $case_index,
						'number'    => $number,
						'threshold' => $threshold,
					)
				);
				continue;
			}

			$number_string   = (string) $number;
			$expected_length = max( strlen( $number_string ), $threshold );
			$unpadded        = ltrim( $result['value'], '0' );
			if ( '' === $unpadded && strspn( $number_string, '0' ) === strlen( $number_string ) ) {
				$unpadded = '0';
			}

			$violations = array();
			if ( strlen( $result['value'] ) !== $expected_length ) {
				$violations[] = 'wrong-length';
			}
			if ( strlen( $number_string ) >= $threshold && $result['value'] !== $number_string ) {
				$violations[] = 'changed-unpadded-number';
			}
			if ( strlen( $number_string ) < $threshold && $unpadded !== ltrim( $number_string, '0' ) && ! ( '0' === $unpadded && '' === ltrim( $number_string, '0' ) ) ) {
				$violations[] = 'changed-sign-or-digits';
			}
			if ( ! preg_match( '/^-?[0-9]+$/', $number_string ) && $result['value'] !== sprintf( '%0' . $threshold . 's', $number_string ) ) {
				$violations[] = 'non-numeric-string-mismatch';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'           => 'zeroise-contract-violation',
					'message'        => 'zeroise() did not preserve sign/digits while padding to the requested threshold.',
					'caseIndex'      => $case_index,
					'number'         => $number,
					'threshold'      => $threshold,
					'expectedLength' => $expected_length,
					'actual'         => $result['value'],
					'violations'     => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'zeroise.length_sign_contract',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_size_format( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		if ( ! function_exists( 'size_format' ) ) {
			return self::skip_row( $ctx, 'size_format.unit_monotonicity', 'size_format() is unavailable.' );
		}

		$failures       = array();
		$previous_rank  = -1;
		$previous_bytes = null;
		$checked        = 0;

		foreach ( $cases as $case_index => $case ) {
			$bytes    = $case['bytes'];
			$decimals = $case['decimals'];
			$call     = self::call(
				'size_format',
				static function () use ( $bytes, $decimals ) {
					return \size_format( $bytes, $decimals );
				}
			);
			++$checked;

			if ( $bytes < 0 ) {
				if ( $call['ok'] && false === $call['value'] ) {
					continue;
				}
				$failures[] = array(
					'name'      => 'size-format-negative-not-false',
					'message'   => 'size_format() should return false for generated negative byte counts.',
					'caseIndex' => $case_index,
					'bytes'     => $bytes,
					'actual'    => self::call_summary( $call ),
				);
				continue;
			}

			if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
				$failures[] = self::call_failure(
					'size-format-call-failed',
					'size_format() failed or returned a non-string value for a non-negative byte count.',
					$call,
					array(
						'caseIndex' => $case_index,
						'bytes'     => $bytes,
						'decimals'  => $decimals,
					)
				);
				continue;
			}

			$unit = self::size_format_unit( $call['value'] );
			$rank = self::size_unit_rank( $unit );
			$violations = array();
			if ( null === $unit ) {
				$violations[] = 'unparseable-unit';
			} elseif ( $rank < $previous_rank && null !== $previous_bytes && $bytes >= $previous_bytes ) {
				$violations[] = 'unit-rank-decreased';
			}
			if ( ! preg_match( '/^[0-9][0-9,.]* (B|KB|MB|GB|TB|PB|EB|ZB|YB)$/', $call['value'] ) ) {
				$violations[] = 'unexpected-format-shape';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'         => 'size-format-unit-violation',
					'message'      => 'size_format() output had an unexpected shape or non-monotonic unit class.',
					'caseIndex'    => $case_index,
					'bytes'        => $bytes,
					'decimals'     => $decimals,
					'actual'       => $call['value'],
					'previousRank' => $previous_rank,
					'currentRank'  => $rank,
					'violations'   => $violations,
				);
			}

			if ( null !== $unit ) {
				$previous_rank = max( $previous_rank, $rank );
			}
			$previous_bytes = $bytes;
		}

		return self::check_row(
			$ctx,
			'size_format.unit_monotonicity',
			$failures,
			array( 'cases' => $checked )
		);
	}

	private static function check_human_time_diff( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		if ( ! function_exists( 'human_time_diff' ) ) {
			return self::skip_row( $ctx, 'human_time_diff.finite_symmetric_output', 'human_time_diff() is unavailable.' );
		}

		$failures = array();
		foreach ( $cases as $case_index => $case ) {
			$from    = $case['from'];
			$to      = $case['to'];
			$forward = self::call(
				'human_time_diff',
				static function () use ( $from, $to ) {
					return \human_time_diff( $from, $to );
				}
			);
			$reverse = self::call(
				'human_time_diff:reverse',
				static function () use ( $from, $to ) {
					return \human_time_diff( $to, $from );
				}
			);

			if ( ! $forward['ok'] || ! is_string( $forward['value'] ) || ! $reverse['ok'] || ! is_string( $reverse['value'] ) ) {
				$failures[] = array(
					'name'      => 'human-time-diff-call-failed',
					'message'   => 'human_time_diff() failed or returned a non-string value.',
					'caseIndex' => $case_index,
					'from'      => $from,
					'to'        => $to,
					'forward'   => self::call_summary( $forward ),
					'reverse'   => self::call_summary( $reverse ),
				);
				continue;
			}

			$violations = array();
			if ( $forward['value'] !== $reverse['value'] ) {
				$violations[] = 'not-symmetric';
			}
			if ( ! preg_match( '/^[1-9][0-9]* (second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)$/', $forward['value'] ) ) {
				$violations[] = 'unexpected-output-shape';
			}
			if ( preg_match( '/[<>&"\']/', $forward['value'] ) ) {
				$violations[] = 'raw-html-delimiter';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'human-time-diff-violation',
					'message'    => 'human_time_diff() output was not finite, symmetric, and plain text shaped.',
					'caseIndex'  => $case_index,
					'from'       => $from,
					'to'         => $to,
					'forward'    => $forward['value'],
					'reverse'    => $reverse['value'],
					'violations' => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'human_time_diff.finite_symmetric_output',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_hex_colors( \ComponentFuzz\FuzzContext $ctx, array $cases ): array {
		foreach ( array( 'sanitize_hex_color', 'sanitize_hex_color_no_hash' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				return self::skip_row( $ctx, 'sanitize_hex_color.shape_agreement', "{$function}() is unavailable." );
			}
		}

		$failures = array();
		foreach ( $cases as $case_index => $color ) {
			$with_hash = self::call(
				'sanitize_hex_color',
				static function () use ( $color ) {
					return \sanitize_hex_color( $color );
				}
			);
			$no_hash   = self::call(
				'sanitize_hex_color_no_hash',
				static function () use ( $color ) {
					return \sanitize_hex_color_no_hash( $color );
				}
			);

			if ( ! $with_hash['ok'] || ! $no_hash['ok'] ) {
				$failures[] = array(
					'name'      => 'hex-color-call-failed',
					'message'   => 'Hex color sanitization threw for a generated color.',
					'caseIndex' => $case_index,
					'input'     => $color,
					'withHash'  => self::call_summary( $with_hash ),
					'noHash'    => self::call_summary( $no_hash ),
				);
				continue;
			}

			$violations = array();
			if ( null !== $with_hash['value'] && '' !== $with_hash['value'] && ( ! is_string( $with_hash['value'] ) || ! preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $with_hash['value'] ) ) ) {
				$violations[] = 'with-hash-bad-shape';
			}
			if ( null !== $no_hash['value'] && '' !== $no_hash['value'] && ( ! is_string( $no_hash['value'] ) || ! preg_match( '/^([A-Fa-f0-9]{3}){1,2}$/', $no_hash['value'] ) ) ) {
				$violations[] = 'no-hash-bad-shape';
			}
			if ( is_string( $with_hash['value'] ) && '' !== $with_hash['value'] ) {
				$expected_no_hash = substr( $with_hash['value'], 1 );
				$actual_no_hash   = \sanitize_hex_color_no_hash( $with_hash['value'] );
				if ( $expected_no_hash !== $actual_no_hash ) {
					$violations[] = 'with-hash-no-hash-disagreement';
				}
			}
			if ( is_string( $no_hash['value'] ) && '' !== $no_hash['value'] && null === \sanitize_hex_color( '#' . $no_hash['value'] ) ) {
				$violations[] = 'no-hash-cannot-roundtrip-with-hash';
			}

			if ( array() !== $violations ) {
				$failures[] = array(
					'name'       => 'hex-color-shape-violation',
					'message'    => 'Hex color sanitizers disagreed or returned a value outside the documented shape.',
					'caseIndex'  => $case_index,
					'input'      => $color,
					'withHash'   => self::call_summary( $with_hash ),
					'noHash'     => self::call_summary( $no_hash ),
					'violations' => $violations,
				);
			}
		}

		return self::check_row(
			$ctx,
			'sanitize_hex_color.shape_agreement',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_utf8_and_accents( \ComponentFuzz\FuzzContext $ctx, array $inputs ): array {
		$failures = array();
		$skipped  = array();
		$cases    = 0;

		if ( function_exists( 'seems_utf8' ) ) {
			foreach ( $inputs['utf8Cases'] as $case_index => $case ) {
				$call = self::call(
					'seems_utf8',
					static function () use ( $case ) {
						return \seems_utf8( $case['input'] );
					}
				);
				++$cases;
				if ( ! $call['ok'] || ! is_bool( $call['value'] ) ) {
					$failures[] = self::call_failure(
						'seems-utf8-call-failed',
						'seems_utf8() failed or returned a non-bool value.',
						$call,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $case['input'] ),
						)
					);
					continue;
				}
				if ( $call['value'] !== $case['seemsUtf8'] ) {
					$failures[] = array(
						'name'      => 'seems-utf8-classification-mismatch',
						'message'   => 'seems_utf8() did not match the generated byte-sequence model expectation.',
						'caseIndex' => $case_index,
						'input'     => self::describe_string( $case['input'] ),
						'expected'  => $case['seemsUtf8'],
						'actual'    => $call['value'],
					);
				}
			}
		} else {
			$skipped[] = 'seems_utf8';
		}

		if ( function_exists( 'utf8_uri_encode' ) ) {
			foreach ( $inputs['roundTripStrings'] as $case_index => $input ) {
				$limit = 12 + ( $case_index * 7 );
				$call  = self::call(
					'utf8_uri_encode',
					static function () use ( $input, $limit ) {
						return \utf8_uri_encode( $input, $limit, true );
					}
				);
				++$cases;
				if ( ! $call['ok'] || ! is_string( $call['value'] ) ) {
					$failures[] = self::call_failure(
						'utf8-uri-encode-call-failed',
						'utf8_uri_encode() failed or returned a non-string value.',
						$call,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
							'limit'     => $limit,
						)
					);
					continue;
				}
				$violations = array();
				if ( strlen( $call['value'] ) > $limit ) {
					$violations[] = 'length-limit-exceeded';
				}
				if ( preg_match( '/[^\x00-\x7F]/', $call['value'] ) ) {
					$violations[] = 'non-ascii-output';
				}
				if ( ! preg_match( '/^(?:%[0-9A-Fa-f]{2}|[\x00-\x24\x26-\x7F])*$/', $call['value'] ) ) {
					$violations[] = 'malformed-percent-encoding';
				}
				if ( array() !== $violations ) {
					$failures[] = array(
						'name'       => 'utf8-uri-encode-shape-violation',
						'message'    => 'utf8_uri_encode() output exceeded the byte limit or produced malformed encoded output.',
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'limit'      => $limit,
						'output'     => self::describe_string( $call['value'] ),
						'violations' => $violations,
					);
				}
			}
		} else {
			$skipped[] = 'utf8_uri_encode';
		}

		if ( function_exists( 'remove_accents' ) ) {
			foreach ( self::accent_cases() as $case_index => $input ) {
				$first = self::call(
					'remove_accents',
					static function () use ( $input ) {
						return \remove_accents( $input );
					}
				);
				++$cases;
				if ( ! $first['ok'] || ! is_string( $first['value'] ) ) {
					$failures[] = self::call_failure(
						'remove-accents-call-failed',
						'remove_accents() failed or returned a non-string value.',
						$first,
						array(
							'caseIndex' => $case_index,
							'input'     => self::describe_string( $input ),
						)
					);
					continue;
				}
				$again = \remove_accents( $first['value'] );
				if ( $again !== $first['value'] || ( self::is_ascii( $input ) && $first['value'] !== $input ) ) {
					$failures[] = array(
						'name'       => 'remove-accents-idempotence-violation',
						'message'    => 'remove_accents() was not idempotent or changed an ASCII-only input.',
						'caseIndex'  => $case_index,
						'input'      => self::describe_string( $input ),
						'first'      => self::describe_string( $first['value'] ),
						'second'     => self::describe_string( $again ),
						'isAscii'    => self::is_ascii( $input ),
						'difference' => self::first_string_difference( $first['value'], $again ),
					);
				}
			}
		} else {
			$skipped[] = 'remove_accents';
		}

		return self::check_row(
			$ctx,
			'utf8_uri_accents.shape_contracts',
			$failures,
			array(
				'cases'   => $cases,
				'skipped' => $skipped,
			)
		);
	}

	private static function generate_text( \ComponentFuzz\FuzzContext $ctx, int $max_bytes ): string {
		$pieces = array(
			'plain',
			'&',
			'&amp;',
			'&#039;',
			'&#x27;',
			'<b>',
			'</b>',
			'<script>alert(1)</script>',
			"quote'\"slash\\",
			"line\nbreak",
			"carriage\rreturn",
			"tab\tvalue",
			"\x00",
			"\x01",
			"\x7F",
			"\xC0\xAF",
			"\xF0\x28\x8C\x28",
			"caf\u{00E9}",
			"e\u{0301}",
			"\u{1F642}",
			"\u{4E2D}\u{6587}",
			'[fmt attr="x"]body[/fmt]',
			'http://example.com/path?q=1',
			'user@example.com',
		);

		$count = $ctx->int( 4, 36 );
		$out   = '';
		for ( $i = 0; $i < $count; ++$i ) {
			$out .= $ctx->choice( $pieces );
			if ( $ctx->bool( 65 ) ) {
				$out .= $ctx->choice( array( ' ', "\n", "\t", '/', '-', '&', '<', '>' ) );
			}
			if ( strlen( $out ) >= $max_bytes ) {
				break;
			}
		}

		return self::trim_input( $out );
	}

	private static function generate_round_trip_text( \ComponentFuzz\FuzzContext $ctx ): string {
		$pieces = array(
			'alpha',
			'beta',
			'<tag>',
			'"double"',
			"'single'",
			"line\nbreak",
			"tab\tvalue",
			"caf\u{00E9}",
			"e\u{0301}",
			"\u{2603}",
			"\u{4E2D}\u{6587}",
		);

		$out   = '';
		$count = $ctx->int( 2, 12 );
		for ( $i = 0; $i < $count; ++$i ) {
			$out .= $ctx->choice( $pieces ) . $ctx->choice( array( ' ', "\n", '/', '-' ) );
		}

		return self::trim_input( $out );
	}

	private static function generate_wptexturize_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token  = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 10 ) ) );
		$word   = '' === $token ? 'alpha' : $token;
		$width  = $ctx->int( 2, 99 );
		$height = $ctx->int( 2, 99 );
		$feet   = $ctx->int( 2, 19 );
		$inch   = $ctx->int( 2, 47 );

		$cases = array(
			array(
				'label'    => 'double-quotes-em-dash-dimension-ellipsis',
				'input'    => '"' . $word . '" -- ' . $width . 'x' . $height . '...',
				'expected' => '&#8220;' . $word . '&#8221; &#8212; ' . $width . '&#215;' . $height . '&#8230;',
			),
			array(
				'label'    => 'single-quotes-en-dash-apostrophe',
				'input'    => "'quoted' - " . $word . "'s",
				'expected' => '&#8216;quoted&#8217; &#8211; ' . $word . '&#8217;s',
			),
			array(
				'label'    => 'numeric-primes',
				'input'    => $feet . "' by " . $inch . '"',
				'expected' => $feet . '&#8242; by ' . $inch . '&#8243;',
			),
			array(
				'label'    => 'ampersand-and-cockney',
				'input'    => "AT&T 'twas 'til now",
				'expected' => 'AT&#038;T &#8217;twas &#8217;til now',
			),
			array(
				'label'    => 'html-attribute-ampersand-and-body-text',
				'input'    => '<a href="http://example.test?a=1&b=2">"link"</a>',
				'expected' => '<a href="http://example.test?a=1&#038;b=2">&#8220;link&#8221;</a>',
			),
			array(
				'label'    => 'default-protected-pre',
				'input'    => 'Before "' . $word . '" -- <pre>"raw" -- 14x24 &</pre> after \'tail\'...',
				'expected' => 'Before &#8220;' . $word . '&#8221; &#8212; <pre>"raw" -- 14x24 &</pre> after &#8216;tail&#8217;&#8230;',
				'contains' => array( '<pre>"raw" -- 14x24 &</pre>' ),
			),
			array(
				'label'    => 'filtered-protected-mark',
				'input'    => 'Before <mark>"raw" -- 14x24 &</mark> after "ok"',
				'expected' => 'Before <mark>"raw" -- 14x24 &</mark> after &#8220;ok&#8221;',
				'contains' => array( '<mark>"raw" -- 14x24 &</mark>' ),
			),
			array(
				'label'    => 'filtered-protected-shortcode',
				'input'    => 'Before [fmtkeep]"raw" -- 14x24 &[/fmtkeep] after "ok"',
				'expected' => 'Before [fmtkeep]"raw" -- 14x24 &[/fmtkeep] after &#8220;ok&#8221;',
				'contains' => array( '[fmtkeep]"raw" -- 14x24 &[/fmtkeep]' ),
			),
			array(
				'label'    => 'registered-unprotected-shortcode-content',
				'input'    => '[fmttext]"raw" -- ' . $width . 'x' . $height . ' &[/fmttext]',
				'expected' => '[fmttext]&#8220;raw&#8221; &#8212; ' . $width . '&#215;' . $height . ' &#038;[/fmttext]',
			),
			array(
				'label'    => 'html-comments-protected',
				'input'    => '<!-- "raw" -- --> "' . $word . '"',
				'expected' => '<!-- "raw" -- --> &#8220;' . $word . '&#8221;',
				'contains' => array( '<!-- "raw" -- -->' ),
			),
			array(
				'label'       => 'punycode-double-hyphen-preserved',
				'input'       => 'xn--bcher-kva -- plain--dash',
				'contains'    => array( 'xn--bcher-kva', '&#8212;', 'plain&#8211;dash' ),
				'notContains' => array( 'xn&#8211;bcher-kva' ),
			),
		);

		$plain_words = array( 'alpha', 'beta', 'gamma', 'delta', $word );
		for ( $i = 0; $i < 6; ++$i ) {
			$left  = $ctx->choice( $plain_words );
			$right = $ctx->choice( $plain_words );
			$w     = $ctx->int( 2, 99 );
			$h     = $ctx->int( 2, 99 );
			$cases[] = array(
				'label'    => 'generated-pattern-' . $i,
				'input'    => '"' . $left . '" - ' . $right . "'s " . $w . 'x' . $h . '...',
				'expected' => '&#8220;' . $left . '&#8221; &#8211; ' . $right . '&#8217;s ' . $w . '&#215;' . $h . '&#8230;',
			);
		}

		return $cases;
	}

	private static function generate_autop_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$paragraphs = array(
			'alpha beta gamma',
			'[fmt id="1"]short[/fmt]',
			'[fmt /]',
			'[fmt-box]nested [fmt]inner[/fmt][/fmt-box]',
			"line one\nline two",
			'<strong>inline</strong> text',
		);

		$cases = array(
			array( 'input' => "alpha\n\nbeta", 'br' => true ),
			array( 'input' => "[fmt]\n\ntext", 'br' => true ),
			array( 'input' => "[fmt]body[/fmt]\n\n[fmt /]", 'br' => false ),
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$count = $ctx->int( 2, 5 );
			$parts = array();
			for ( $j = 0; $j < $count; ++$j ) {
				$parts[] = $ctx->choice( $paragraphs );
			}
			$cases[] = array(
				'input' => implode( "\n\n", $parts ),
				'br'    => $ctx->bool(),
			);
		}

		return $cases;
	}

	private static function generate_clickable_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$urls = array(
			'http://example.com/a?b=1',
			'https://sub.example.test/path_(one)',
			'www.example.com/path',
			'ftp://example.com/file.txt',
			'user@example.com',
			'name.surname+tag@example.co.uk',
		);

		$cases = array(
			array( 'input' => 'Visit http://example.com/a?b=1 today.', 'minLinks' => 1 ),
			array( 'input' => 'Mail user@example.com and open www.example.com/path', 'minLinks' => 2 ),
			array( 'input' => '<code>http://example.com/not-linked</code> http://example.com/linked', 'minLinks' => 1 ),
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$count = $ctx->int( 1, 4 );
			$parts = array();
			for ( $j = 0; $j < $count; ++$j ) {
				$parts[] = $ctx->choice( array( 'See', 'mail', 'ref', 'then', 'and' ) );
				$parts[] = $ctx->choice( $urls );
			}
			$cases[] = array(
				'input'    => implode( ' ', $parts ),
				'minLinks' => $count,
			);
		}

		return $cases;
	}

	private static function generate_text_link_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$token = strtolower( preg_replace( '/[^a-z0-9]+/', '', $ctx->identifier( 4, 10 ) ) );
		if ( '' === $token ) {
			$token = 'cfuzz';
		}

		$words = array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', $token );
		$cases = array(
			array(
				'label'        => 'html-and-entity-boundary',
				'trimText'     => '<p>alpha <strong>beta</strong> gamma delta epsilon</p>',
				'trimWords'    => 3,
				'trimMore'     => ' [&more]',
				'excerptText'  => '<b>Alpha &amp; beta</b> and <script>drop()</script> trailing',
				'excerptCount' => 12,
				'excerptMore'  => '...',
			),
			array(
				'label'        => 'newlines-and-partial-entity',
				'trimText'     => "one\ntwo\tthree\r\nfour five",
				'trimWords'    => 4,
				'trimMore'     => '...',
				'excerptText'  => 'Fish &amp; chips &notin; raw',
				'excerptCount' => 10,
				'excerptMore'  => ' [cut]',
			),
		);

		for ( $i = 0; $i < 6; ++$i ) {
			$count      = $ctx->int( 4, 8 );
			$trim_words = array();
			for ( $j = 0; $j < $count; ++$j ) {
				$trim_words[] = $ctx->choice( $words );
			}

			$entity = $ctx->choice( array( '&amp;', '&#039;', '&#x27;', '&copy;' ) );
			$cases[] = array(
				'label'        => 'generated-text-link-' . $i,
				'trimText'     => '<em>' . implode( "</em>\n<strong>", $trim_words ) . '</strong> tail',
				'trimWords'    => $ctx->int( 2, max( 2, $count - 1 ) ),
				'trimMore'     => $ctx->choice( array( '...', ' [&hellip;]', ' --more--' ) ),
				'excerptText'  => '<span>' . $trim_words[0] . ' ' . $entity . ' ' . $trim_words[1] . '</span> ' . $ctx->choice( $words ) . ' &partial',
				'excerptCount' => $ctx->int( 6, 20 ),
				'excerptMore'  => $ctx->choice( array( '', '...', ' [cut]' ) ),
			);
		}

		foreach ( $cases as &$case ) {
			$case['relativeLinks'] = array(
				'https://example.test/path/' . $token . '?a=1#frag' => '/path/' . $token . '?a=1#frag',
				'//cdn.example.test/assets/' . $token . '.js'       => '/assets/' . $token . '.js',
				'/already/relative/' . $token                      => '/already/relative/' . $token,
				'mailto:' . $token . '@example.test'                => 'mailto:' . $token . '@example.test',
			);
			$case['relHtml'] = '<p>'
				. '<a href="https://outside.example/path/' . \esc_attr( $token ) . '" rel="bookmark" data-link="external">external</a> '
				. '<a href="https://example.test/internal/' . \esc_attr( $token ) . '" rel="bookmark" data-link="internal">internal</a>'
				. '</p>';
			$case['emails'] = array(
				$token . '.person+tag@example.test',
				'reader_' . $token . '@sub.example.test',
			);
			$case['wordpressText'] = 'Wordpress PreWordpress wordpress'
				. ' and Wordpress <span>Wordpress</span> (Wordpress) &#8216;Wordpress';
		}
		unset( $case );

		return $cases;
	}

	private static function generate_url_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'input' => 'http://example.com/a?b=1&c=2', 'disallowedProtocol' => false ),
			array( 'input' => 'https://example.test/path with spaces/?q=<tag>&ok=1', 'disallowedProtocol' => false ),
			array( 'input' => 'mailto:user+tag@example.com?subject=Hello World', 'disallowedProtocol' => false ),
			array( 'input' => '/relative/path?x=1&y=two#frag', 'disallowedProtocol' => false ),
			array( 'input' => '//cdn.example.test/lib.js?ver=1', 'disallowedProtocol' => false ),
			array( 'input' => 'ftp://example.com/file.txt', 'disallowedProtocol' => false ),
			array( 'input' => 'javascript:alert(1)', 'disallowedProtocol' => true ),
			array( 'input' => 'data:text/html,<script>alert(1)</script>', 'disallowedProtocol' => true ),
			array( 'input' => "vbscript:msgbox(1)\x00", 'disallowedProtocol' => true ),
		);

		$schemes = array( 'http', 'https', 'mailto', 'ftp', 'javascript', 'data', 'vbscript', '' );
		for ( $i = 0; $i < 10; ++$i ) {
			$scheme       = $ctx->choice( $schemes );
			$path         = rawurlencode( $ctx->text( 0, 18 ) );
			$query        = rawurlencode( $ctx->text( 0, 18 ) );
			$is_disallowed = in_array( $scheme, array( 'javascript', 'data', 'vbscript' ), true );

			if ( '' === $scheme ) {
				$input = '/' . $path . '?q=' . $query;
			} elseif ( 'mailto' === $scheme ) {
				$input = 'mailto:user' . $i . '@example.test?subject=' . $query;
			} elseif ( $is_disallowed ) {
				$input = $scheme . ':' . $ctx->choice( array( 'alert(1)', '<svg/onload=1>', 'text/html,<b>x</b>' ) );
			} else {
				$input = $scheme . '://example.test/' . $path . '?q=' . $query . '&unsafe=<tag>';
			}

			$cases[] = array(
				'input'              => $input,
				'disallowedProtocol' => $is_disallowed,
			);
		}

		return $cases;
	}

	private static function generate_deep_map_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$object        = new \stdClass();
		$object->title = "quote'\"slash\\";
		$object->url   = 'https://example.test/a path/?q=1&two=2';
		$object->meta  = array(
			'percent' => 'a+b c%20',
			'bool'    => false,
			'count'   => 42,
		);

		$cases = array(
			array(
				'label'      => 'array-object-scalar-mix',
				'value'      => array(
					'plain'  => 'alpha beta',
					'nested' => array(
						"quote'\"slash\\",
						'two words',
						5 => 12,
					),
					'object' => $object,
				),
				'slashValue' => array(
					'title'  => "quote'\"slash\\",
					'fields' => array(
						'one' => "O'Reilly",
						'two' => 'C:\\Temp\\file.txt',
					),
				),
			),
			array(
				'label'      => 'encoded-bytes-and-unicode',
				'value'      => array(
					'bytes' => "bad\xC3 percent%2F space and+plus",
					'utf8'  => "caf\u{00E9} e\u{0301} \u{4E2D}\u{6587}",
					'flags' => array( true, false, 3.5 ),
				),
				'slashValue' => array(
					'bytes'  => "bad\xC3",
					'quoted' => array( '"double"', "'single'", "slash\\tail" ),
					'flags'  => array( true, false, 3.5 ),
				),
			),
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$cases[] = array(
				'label'      => 'generated-deep-' . $i,
				'value'      => self::generate_deep_value( $ctx->fork( 'deep-value-' . $i ), 0, true ),
				'slashValue' => self::generate_deep_value( $ctx->fork( 'deep-slash-' . $i ), 0, false ),
			);
		}

		return $cases;
	}

	private static function generate_deep_value( \ComponentFuzz\FuzzContext $ctx, int $depth, bool $include_objects ) {
		if ( $depth >= 3 || ( $depth > 0 && $ctx->bool( 35 ) ) ) {
			return self::generate_deep_leaf( $ctx->fork( 'leaf-' . $depth ) );
		}

		if ( $include_objects && $ctx->bool( 35 ) ) {
			$object = new \stdClass();
			$count  = $ctx->int( 1, 4 );
			for ( $i = 0; $i < $count; ++$i ) {
				$name = preg_replace( '/[^A-Za-z0-9_]/', '', $ctx->identifier( 2, 8 ) );
				if ( '' === $name ) {
					$name = 'value';
				}
				$property            = 'p' . $i . '_' . $name;
				$object->$property = self::generate_deep_value( $ctx->fork( 'object-' . $depth . '-' . $i ), $depth + 1, true );
			}

			return $object;
		}

		$count = $ctx->int( 1, 4 );
		$value = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$key           = $ctx->bool( 55 ) ? 'k' . $i . '_' . strtolower( preg_replace( '/[^a-z0-9_]+/i', '', $ctx->identifier( 2, 8 ) ) ) : $i + $ctx->int( 0, 2 );
			$value[ $key ] = self::generate_deep_value( $ctx->fork( 'array-' . $depth . '-' . $i ), $depth + 1, $include_objects );
		}

		return $value;
	}

	private static function generate_deep_leaf( \ComponentFuzz\FuzzContext $ctx ) {
		$strings = array(
			'plain value',
			'two words and+plus',
			'percent%2Fencoded%20value',
			"https://example.test/a path/?q=1&two=2",
			"quote'\"slash\\",
			"line\nbreak\tcolumn",
			"caf\u{00E9} e\u{0301} \u{4E2D}\u{6587}",
			"bad\xC3 bytes",
			'<tag attr="value">&amp;</tag>',
		);

		if ( $ctx->bool( 75 ) ) {
			return $ctx->choice( $strings );
		}

		return $ctx->choice(
			array(
				true,
				false,
				$ctx->int( -99, 999 ),
				$ctx->int( -100, 100 ) / 10,
			)
		);
	}

	private static function generate_identifier_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'input' => 'Post Title With Spaces', 'fallback' => 'fallback-title' ),
			array( 'input' => 'Résumé déjà vu & more', 'fallback' => 'resume-fallback' ),
			array( 'input' => '<script>alert(1)</script> %e2%80%8b zero', 'fallback' => 'script-fallback' ),
			array( 'input' => '../../Path\\Traversal/Name.php', 'fallback' => 'path-fallback' ),
			array( 'input' => '%20%ZZ only invalid !!!', 'fallback' => 'percent-fallback' ),
			array( 'input' => "Tabs\tNewlines\nEmoji \u{1F642}", 'fallback' => 'emoji-fallback' ),
			array( 'input' => '---Already--Slug---', 'fallback' => 'slug-fallback' ),
			array( 'input' => '!!!', 'fallback' => 'only-fallback' ),
		);

		for ( $i = 0; $i < 12; ++$i ) {
			$cases[] = array(
				'input'    => self::generate_text( $ctx->fork( 'identifier-input-' . $i ), 256 ),
				'fallback' => 'fallback-' . strtolower( $ctx->identifier( 3, 12 ) ),
			);
		}

		return $cases;
	}

	private static function generate_file_name_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'input'                           => 'test.phtml.txt',
				'expected'                        => 'test.phtml_.txt',
				'expectIntermediatePhpUnderscore' => true,
			),
			array(
				'input'    => 'àáâãäåæçèéêëìíîïñòóôõöøùúûüýÿ',
				'expected' => 'aaaaaaaeceeeeiiiinoooooouuuuyy',
			),
			array(
				'input'    => "Filename with non-breaking\u{00A0}space.txt",
				'expected' => 'Filename-with-non-breaking-space.txt',
			),
			array(
				'input'    => "Screenshot 2025-02-19 at 2.17.33\u{202F}PM.png",
				'expected' => 'Screenshot-2025-02-19-at-2.17.33-PM.png',
			),
			array(
				'input'    => 'file.......name.png',
				'expected' => 'file.name_.png',
			),
			array(
				'input'             => '_.jpg',
				'expected'          => 'unnamed-file.jpg',
				'expectUnnamedFile' => true,
			),
			array(
				'input'    => '_.no-extension',
				'expected' => 'no-extension',
			),
			array(
				'input'    => 'a%22b.jpg',
				'expected' => 'a22b.jpg',
			),
			array(
				'input'    => urldecode( '%B1myfile.png' ),
				'expected' => 'myfile.png',
			),
			array(
				'input'    => hex2bin( '2e2e5c62797465732dfe882d07' ),
				'expected' => '',
			),
			array(
				'input'    => "question?[slash]/back\\colon:semi;quote'\"amp&hash#null\x00.jpg",
				'expected' => 'questionslashbackcolonsemiquoteamphashnull.jpg',
			),
		);

		$extensions = array( 'jpg', 'png', 'txt', 'php', 'phtml', 'tar.gz', 'svg', 'webp', 'no-extension', '' );
		$pieces     = array(
			'alpha',
			'Résumé',
			"space\u{00A0}name",
			'../escape',
			'..\\escape',
			'semi;colon',
			'quote"name',
			'plus+percent%20name',
			'emoji🙂',
			"bad\xC3",
			"line\nbreak",
			'file.......name',
		);

		for ( $i = 0; $i < 14; ++$i ) {
			$name_parts = array();
			$count      = $ctx->int( 1, 4 );
			for ( $j = 0; $j < $count; ++$j ) {
				$name_parts[] = $ctx->choice( $pieces );
			}
			$extension = $ctx->choice( $extensions );
			$input     = implode( $ctx->choice( array( ' ', '-', '.', '_', '---' ) ), $name_parts );
			if ( '' !== $extension ) {
				$input .= '.' . $extension;
			}

			$cases[] = array( 'input' => self::trim_input( $input ) );
		}

		return $cases;
	}

	private static function generate_user_name_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array(
				'input'          => 'Captain <strong>Awesome</strong>',
				'expectedLoose'  => 'Captain Awesome',
				'expectedStrict' => 'Captain Awesome',
			),
			array(
				'input'          => 'AT&amp;T',
				'expectedLoose'  => 'ATT',
				'expectedStrict' => 'ATT',
			),
			array(
				'input'          => 'AT&amp;T Test;',
				'expectedLoose'  => 'ATT Test;',
				'expectedStrict' => 'ATT Test',
			),
			array(
				'input'          => 'Fran%c3%a7ois',
				'expectedLoose'  => 'Franois',
				'expectedStrict' => 'Franois',
			),
			array(
				'input'          => '()~ab~ˆcˆ!',
				'expectedStrict' => 'abc',
			),
			array(
				'input'          => " spaced\t\n name ",
				'expectedLoose'  => 'spaced name',
				'expectedStrict' => 'spaced name',
			),
			array(
				'input'          => 'safe.name_123@example-test',
				'expectedLoose'  => 'safe.name_123@example-test',
				'expectedStrict' => 'safe.name_123@example-test',
			),
		);

		$pieces = array(
			'User',
			'<b>Admin</b>',
			'Fran%c3%a7ois',
			'AT&amp;T',
			'emoji🙂',
			'()~symbols!',
			"user\nname",
			'email@example.test',
			'wide＿under',
			'percent%22octet',
		);

		for ( $i = 0; $i < 14; ++$i ) {
			$count = $ctx->int( 1, 5 );
			$parts = array();
			for ( $j = 0; $j < $count; ++$j ) {
				$parts[] = $ctx->choice( $pieces );
			}

			$cases[] = array(
				'input' => self::trim_input( implode( $ctx->choice( array( ' ', "\t", '-', '_', '' ) ), $parts ) ),
			);
		}

		return $cases;
	}

	private static function generate_entity_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$entities = array_merge(
			self::known_ncr_entities(),
			array( '&unknown;', '&notit;', '&#038;', '&#x26;', '& raw', 'AT&T', '&&double' )
		);

		$cases = array(
			'AT&T & raw &amp; &copy; |',
			'entities &lt; &gt; &quot; &nbsp; &mdash; &unknown;',
			'&#038; &#x26; &notit; &&',
		);

		for ( $i = 0; $i < 10; ++$i ) {
			$count = $ctx->int( 3, 10 );
			$parts = array();
			for ( $j = 0; $j < $count; ++$j ) {
				$parts[] = $ctx->choice( $entities );
			}
			$cases[] = implode( ' ', $parts );
		}

		return $cases;
	}

	private static function generate_zeroise_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'number' => 0, 'threshold' => 1 ),
			array( 'number' => 7, 'threshold' => 3 ),
			array( 'number' => 1234, 'threshold' => 2 ),
			array( 'number' => -3, 'threshold' => 5 ),
			array( 'number' => '-7', 'threshold' => 4 ),
		);

		for ( $i = 0; $i < 12; ++$i ) {
			$cases[] = array(
				'number'    => $ctx->bool( 20 ) ? (string) $ctx->int( -9999, 99999 ) : $ctx->int( -9999, 99999 ),
				'threshold' => $ctx->int( 0, 10 ),
			);
		}

		return $cases;
	}

	private static function generate_size_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$bytes = array(
			-1,
			0,
			1,
			1023,
			1024,
			1536,
			1024 * 1024 - 1,
			1024 * 1024,
			5 * 1024 * 1024,
			1024 * 1024 * 1024,
			3 * 1024 * 1024 * 1024,
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$bytes[] = $ctx->int( 0, 64 * 1024 * 1024 );
		}

		sort( $bytes, SORT_NUMERIC );

		$cases = array();
		foreach ( $bytes as $index => $byte_count ) {
			$cases[] = array(
				'bytes'    => $byte_count,
				'decimals' => $index % 3,
			);
		}

		return $cases;
	}

	private static function generate_time_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$base   = 1700000000 + $ctx->int( 0, 10000 );
		$deltas = array(
			0,
			1,
			59,
			60,
			61,
			HOUR_IN_SECONDS - 1,
			HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
			WEEK_IN_SECONDS,
			MONTH_IN_SECONDS,
			YEAR_IN_SECONDS,
			3 * YEAR_IN_SECONDS,
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$deltas[] = $ctx->int( 0, 4 * YEAR_IN_SECONDS );
		}

		$cases = array();
		foreach ( $deltas as $delta ) {
			$cases[] = array(
				'from' => $base,
				'to'   => $base + $delta,
			);
		}

		return $cases;
	}

	private static function generate_color_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			'',
			'#fff',
			'#FFFFFF',
			'#123abc',
			'123abc',
			'fff',
			'##fff',
			'#12',
			'#1234',
			'#ggg',
			'rgb(1,2,3)',
			' red ',
		);

		$hex = '0123456789abcdefABCDEF';
		for ( $i = 0; $i < 10; ++$i ) {
			$length = $ctx->choice( array( 2, 3, 4, 6, 7 ) );
			$value  = $ctx->bool( 55 ) ? '#' : '';
			for ( $j = 0; $j < $length; ++$j ) {
				$value .= $ctx->bool( 80 ) ? $hex[ $ctx->int( 0, strlen( $hex ) - 1 ) ] : $ctx->choice( array( 'g', 'z', '-', '_' ) );
			}
			$cases[] = $value;
		}

		return $cases;
	}

	private static function generate_utf8_cases( \ComponentFuzz\FuzzContext $ctx ): array {
		$cases = array(
			array( 'input' => 'plain', 'seemsUtf8' => true ),
			array( 'input' => "caf\u{00E9}", 'seemsUtf8' => true ),
			array( 'input' => "\u{4E2D}\u{6587}", 'seemsUtf8' => true ),
			array( 'input' => "\xC3", 'seemsUtf8' => false ),
			array( 'input' => "\x80", 'seemsUtf8' => false ),
			array( 'input' => "\xE2\x28\xA1", 'seemsUtf8' => false ),
			array( 'input' => "\xF0\x28\x8C\x28", 'seemsUtf8' => false ),
			array( 'input' => "\xC0\xAF", 'seemsUtf8' => true ),
		);

		for ( $i = 0; $i < 8; ++$i ) {
			$valid = $ctx->bool();
			$cases[] = array(
				'input'     => $valid ? self::generate_round_trip_text( $ctx->fork( 'valid-utf8-' . $i ) ) : $ctx->choice( array( "\xC3", "\x80tail", "bad\xE2\x28\xA1", "bad\xF0\x28\x8C\x28" ) ),
				'seemsUtf8' => $valid,
			);
		}

		return $cases;
	}

	private static function accent_cases(): array {
		return array(
			'ASCII only',
			"Caf\u{00E9} No\u{00EB}l fa\u{00E7}ade",
			"\u{00C0}\u{00C1}\u{00C2}\u{00C3}\u{00C4}\u{00C5}",
			"\u{00E6} \u{00F8} \u{00DF} \u{00F1}",
			"e\u{0301} combining mark",
			"\u{0141}\u{00F3}d\u{017A}",
		);
	}

	private static function known_ncr_entities(): array {
		return array( '&quot;', '&amp;', '&lt;', '&gt;', '&nbsp;', '&copy;', '&reg;', '&Aacute;', '&aacute;', '&mdash;', '&hellip;', '&euro;' );
	}

	private static function deep_clone( $value ) {
		return unserialize( serialize( $value ) );
	}

	private static function deep_leaf_count( $value ): int {
		if ( is_array( $value ) ) {
			$count = 0;
			foreach ( $value as $item ) {
				$count += self::deep_leaf_count( $item );
			}

			return $count;
		}

		if ( is_object( $value ) ) {
			$count = 0;
			foreach ( get_object_vars( $value ) as $property_value ) {
				$count += self::deep_leaf_count( $property_value );
			}

			return $count;
		}

		return 1;
	}

	private static function deep_transform( $value, callable $callback ) {
		if ( is_array( $value ) ) {
			$mapped = array();
			foreach ( $value as $key => $item ) {
				$mapped[ $key ] = self::deep_transform( $item, $callback );
			}

			return $mapped;
		}

		if ( is_object( $value ) ) {
			$mapped = clone $value;
			foreach ( get_object_vars( $mapped ) as $property_name => $property_value ) {
				$mapped->$property_name = self::deep_transform( $property_value, $callback );
			}

			return $mapped;
		}

		return $callback( $value );
	}

	private static function deep_shape_signature( $value ) {
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $key => $item ) {
				$items[] = array(
					'key'   => $key,
					'shape' => self::deep_shape_signature( $item ),
				);
			}

			return array(
				'type'  => 'array',
				'items' => $items,
			);
		}

		if ( is_object( $value ) ) {
			$properties = array();
			foreach ( get_object_vars( $value ) as $property_name => $property_value ) {
				$properties[] = array(
					'name'  => $property_name,
					'shape' => self::deep_shape_signature( $property_value ),
				);
			}

			return array(
				'type'       => 'object',
				'class'      => get_class( $value ),
				'properties' => $properties,
			);
		}

		return array( 'type' => 'leaf' );
	}

	private static function deep_map_marker( $leaf ): string {
		return gettype( $leaf ) . ':' . sha1( serialize( $leaf ) );
	}

	private static function deep_same( $left, $right ): bool {
		return serialize( $left ) === serialize( $right );
	}

	private static function deep_serialized_bytes( $value ): int {
		return strlen( serialize( $value ) );
	}

	private static function deep_summary( $value ): array {
		$shape = self::deep_shape_signature( $value );

		return array(
			'type'            => is_object( $value ) ? get_class( $value ) : gettype( $value ),
			'leafCount'       => self::deep_leaf_count( $value ),
			'serializedBytes' => self::deep_serialized_bytes( $value ),
			'shapeSha1'       => sha1( serialize( $shape ) ),
		);
	}

	private static function escaped_context_violations( string $function, string $output ): array {
		$scan = 'esc_xml' === $function ? self::strip_cdata_sections( $output ) : $output;
		$violations = array();

		foreach ( array( '<', '>', '"', "'" ) as $char ) {
			if ( str_contains( $scan, $char ) ) {
				$violations[] = array(
					'type'      => 'raw-delimiter',
					'character' => $char,
					'offset'    => strpos( $scan, $char ),
				);
			}
		}

		return $violations;
	}

	private static function strip_cdata_sections( string $value ): string {
		return (string) preg_replace( '/<!\[CDATA\[.*?\]\]>/s', '', $value );
	}

	private static function url_has_allowed_shape( string $value ): bool {
		if ( preg_match( '/^(?:https?|ftp|mailto):/i', $value ) ) {
			return true;
		}

		return str_starts_with( $value, '/' ) || str_starts_with( $value, '#' ) || str_starts_with( $value, '?' );
	}

	private static function file_name_violations( string $filename, string $again ): array {
		$violations = array();

		if ( $again !== $filename ) {
			$violations[] = 'not-idempotent';
		}
		if ( preg_match( '/[\x00-\x1F\x7F\/\\\\?<>=:;,"\x27&$#*()|~`!{}%+]/', $filename ) ) {
			$violations[] = 'unsafe-character';
		}
		if ( preg_match( '/\s/', $filename ) ) {
			$violations[] = 'whitespace-not-normalized';
		}
		if ( str_contains( $filename, '..' ) ) {
			$violations[] = 'consecutive-periods';
		}
		if ( '' !== $filename && trim( $filename, '.-_' ) !== $filename ) {
			$violations[] = 'not-trimmed';
		}
		if ( str_contains( $filename, '%20' ) || str_contains( $filename, '+' ) ) {
			$violations[] = 'encoded-space-or-plus-left';
		}

		return $violations;
	}

	private static function user_name_violations( string $loose, string $strict, string $loose_again, string $strict_again ): array {
		$violations = array();

		if ( $loose_again !== $loose ) {
			$violations[] = 'loose-not-idempotent';
		}
		if ( $strict_again !== $strict ) {
			$violations[] = 'strict-not-idempotent';
		}
		if ( preg_match( '/<[^>]*>|%[a-fA-F0-9]{2}|&.+?;/', $loose . $strict ) ) {
			$violations[] = 'tag-percent-or-entity-left';
		}
		if ( trim( $loose ) !== $loose || trim( $strict ) !== $strict ) {
			$violations[] = 'not-trimmed';
		}
		if ( preg_match( '/\s{2,}/', $loose . "\n" . $strict ) ) {
			$violations[] = 'whitespace-not-collapsed';
		}
		if ( ! preg_match( '/^[A-Za-z0-9 _.\-@]*$/', $strict ) ) {
			$violations[] = 'strict-unsafe-shape';
		}
		if ( strlen( $strict ) > strlen( $loose ) ) {
			$violations[] = 'strict-longer-than-loose';
		}

		return $violations;
	}

	private static function has_file_filter_call( array $calls, string $raw, string $sanitized ): bool {
		foreach ( $calls as $call ) {
			if ( $raw === ( $call['raw'] ?? null ) && $sanitized === ( $call['sanitized'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_user_filter_call( array $calls, string $raw, bool $strict ): bool {
		foreach ( $calls as $call ) {
			if ( $raw === ( $call['raw'] ?? null ) && $strict === ( $call['strict'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	private static function expected_wp_trim_words( string $text, int $num_words, string $more ): string {
		$text        = \wp_strip_all_tags( $text );
		$words_array = preg_split( "/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY );

		if ( count( $words_array ) > $num_words ) {
			array_pop( $words_array );
			$text = implode( ' ', $words_array ) . $more;
		} else {
			$text = implode( ' ', $words_array );
		}

		return $text;
	}

	private static function expected_wp_html_excerpt( string $str, int $count, string $more ): string {
		$str     = \wp_strip_all_tags( $str, true );
		$excerpt = mb_substr( $str, 0, $count );
		$excerpt = preg_replace( '/&[^;\s]{0,6}$/', '', $excerpt );

		if ( $str !== $excerpt ) {
			$excerpt = trim( $excerpt ) . $more;
		}

		return $excerpt;
	}

	private static function rel_tokens_for_anchor( string $html, string $marker ): array {
		if ( ! preg_match( '/<a\b(?=[^>]*' . preg_quote( $marker, '/' ) . ')[^>]*\brel=(["\'])(.*?)\1[^>]*>/i', $html, $matches ) ) {
			return array();
		}

		$tokens = preg_split( '/\s+/', trim( html_entity_decode( $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( $tokens ?: array() ) );
	}

	private static function tokens_include_once( array $tokens, array $required ): bool {
		foreach ( $required as $token ) {
			if ( 1 !== count( array_keys( $tokens, $token, true ) ) ) {
				return false;
			}
		}

		return count( $tokens ) === count( array_unique( $tokens ) );
	}

	private static function check_row( \ComponentFuzz\FuzzContext $ctx, string $invariant, array $failures, array $data = array() ): array {
		$data['failureCount'] = count( $failures );
		if ( array() !== $failures ) {
			$data['failures'] = array_slice( $failures, 0, 8 );
		}

		return self::row( $ctx, $invariant, array() === $failures, $data );
	}

	private static function row( \ComponentFuzz\FuzzContext $ctx, string $invariant, bool $ok, array $data, ?string $status = null ): array {
		return array(
			'ok'        => $ok,
			'status'    => $status ?? ( $ok ? 'passed' : 'failed' ),
			'surface'   => $ctx->surface(),
			'invariant' => $invariant,
			'seed'      => $ctx->seed(),
			'iteration' => $ctx->iteration(),
			'data'      => $data,
		);
	}

	private static function skip_row( \ComponentFuzz\FuzzContext $ctx, string $invariant, string $reason, array $data = array() ): array {
		$data['reason'] = $reason;
		return self::row( $ctx, $invariant, true, $data, 'skipped' );
	}

	private static function call( string $label, callable $callback ): array {
		$started = hrtime( true );
		try {
			return array(
				'ok'         => true,
				'label'      => $label,
				'value'      => $callback(),
				'durationMs' => round( ( hrtime( true ) - $started ) / 1000000, 3 ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'         => false,
				'label'      => $label,
				'throwable'  => self::describe_throwable( $e ),
				'durationMs' => round( ( hrtime( true ) - $started ) / 1000000, 3 ),
			);
		}
	}

	private static function call_failure( string $name, string $message, array $call, array $extra = array() ): array {
		return array_merge(
			array(
				'name'    => $name,
				'message' => $message,
				'call'    => self::call_summary( $call ),
			),
			$extra
		);
	}

	private static function missing_function_failure( string $function ): array {
		return array(
			'name'     => 'missing-function',
			'message'  => "{$function}() is unavailable.",
			'function' => $function,
		);
	}

	private static function call_summary( array $call ): array {
		$summary = array(
			'ok'         => (bool) ( $call['ok'] ?? false ),
			'label'      => $call['label'] ?? null,
			'durationMs' => $call['durationMs'] ?? null,
		);

		if ( empty( $call['ok'] ) ) {
			$summary['throwable'] = $call['throwable'] ?? null;
			return $summary;
		}

		$value = $call['value'] ?? null;
		if ( is_string( $value ) ) {
			$summary['value'] = self::describe_string( $value );
		} elseif ( is_scalar( $value ) || null === $value ) {
			$summary['value'] = $value;
			$summary['type']  = gettype( $value );
		} elseif ( is_array( $value ) ) {
			$summary['type']  = 'array';
			$summary['count'] = count( $value );
		} else {
			$summary['type'] = is_object( $value ) ? get_class( $value ) : gettype( $value );
		}

		return $summary;
	}

	private static function describe_string( string $value ): array {
		return array(
			'bytes'     => strlen( $value ),
			'sha1'      => sha1( $value ),
			'validUtf8' => self::is_valid_utf8( $value ),
			'preview'   => self::preview( $value ),
		);
	}

	private static function preview( string $value ): string {
		$printable = str_replace(
			array( "\\", "\r", "\n", "\t" ),
			array( '\\\\', '\\r', '\\n', '\\t' ),
			$value
		);
		$printable = preg_replace_callback(
			'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
			static function ( array $matches ): string {
				return sprintf( '\\x%02X', ord( $matches[0] ) );
			},
			$printable
		);

		if ( strlen( $printable ) > self::PREVIEW_BYTES ) {
			return substr( $printable, 0, self::PREVIEW_BYTES ) . '...';
		}

		return $printable;
	}

	private static function describe_throwable( \Throwable $throwable ): array {
		return array(
			'class'   => get_class( $throwable ),
			'message' => $throwable->getMessage(),
			'file'    => $throwable->getFile(),
			'line'    => $throwable->getLine(),
		);
	}

	private static function first_string_difference( string $left, string $right ): ?array {
		$max = min( strlen( $left ), strlen( $right ) );
		for ( $i = 0; $i < $max; ++$i ) {
			if ( $left[ $i ] !== $right[ $i ] ) {
				return array(
					'offset' => $i,
					'left'   => self::preview( substr( $left, max( 0, $i - 20 ), 60 ) ),
					'right'  => self::preview( substr( $right, max( 0, $i - 20 ), 60 ) ),
				);
			}
		}

		if ( strlen( $left ) !== strlen( $right ) ) {
			return array(
				'offset' => $max,
				'left'   => self::preview( substr( $left, max( 0, $max - 20 ), 60 ) ),
				'right'  => self::preview( substr( $right, max( 0, $max - 20 ), 60 ) ),
			);
		}

		return null;
	}

	private static function size_format_unit( string $value ): ?string {
		if ( preg_match( '/ (B|KB|MB|GB|TB|PB|EB|ZB|YB)$/', $value, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	private static function size_unit_rank( ?string $unit ): int {
		$ranks = array(
			'B'  => 0,
			'KB' => 1,
			'MB' => 2,
			'GB' => 3,
			'TB' => 4,
			'PB' => 5,
			'EB' => 6,
			'ZB' => 7,
			'YB' => 8,
		);

		return null === $unit ? -1 : ( $ranks[ $unit ] ?? -1 );
	}

	private static function is_valid_utf8( string $value ): bool {
		return function_exists( 'wp_is_valid_utf8' ) ? \wp_is_valid_utf8( $value ) : (bool) preg_match( '//u', $value );
	}

	private static function is_ascii( string $value ): bool {
		return ! preg_match( '/[^\x00-\x7F]/', $value );
	}

	private static function trim_input( string $input ): string {
		if ( strlen( $input ) <= self::MAX_INPUT_BYTES ) {
			return $input;
		}

		return substr( $input, 0, self::MAX_INPUT_BYTES );
	}
}
