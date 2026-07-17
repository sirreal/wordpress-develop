<?php
namespace ComponentFuzz\Surfaces;

final class KsesSurface {
	public const NAME = 'kses';

	private const DEFAULT_CASES = 18;
	private const MAX_CASES     = 96;
	private const PREVIEW_BYTES = 240;

	/**
	 * URI attribute names mirrored from wp_kses_uri_attributes() for fallback
	 * inspection when WordPress is not bootstrapped far enough to expose it.
	 */
	private const FALLBACK_URI_ATTRIBUTES = array(
		'action',
		'archive',
		'background',
		'cite',
		'classid',
		'codebase',
		'data',
		'formaction',
		'href',
		'icon',
		'longdesc',
		'manifest',
		'poster',
		'profile',
		'src',
		'usemap',
		'xmlns',
	);

	private const DANGEROUS_PROTOCOLS = array(
		'data',
		'javascript',
		'livescript',
		'mocha',
		'vbscript',
	);

	public static function run( \ComponentFuzz\FuzzContext $ctx ): array {
		$seed       = self::seed_from_context( $ctx );
		$case_count = self::int_from_context( $ctx, 'cases', self::DEFAULT_CASES, 4, self::MAX_CASES );
		$results    = array();
		$missing    = array();

		foreach ( array( 'wp_kses', 'wp_kses_allowed_html', 'wp_kses_bad_protocol' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				$missing[] = $function_name;
			}
		}

		if ( ! empty( $missing ) ) {
			return array(
				self::fail(
					$seed,
					null,
					'bootstrap.required-functions-available',
					'',
					'WordPress KSES functions are loaded',
					array( 'missing' => $missing ),
					array( 'failureClass' => 'missing-wordpress-function' )
				),
			);
		}

		$global_snapshot            = self::snapshot_globals( self::kses_global_names() );
		$pre_kses_hook_snapshot     = self::snapshot_hook( 'pre_kses' );
		$pre_kses_hook_signature    = self::hook_signature( 'pre_kses' );
		$pre_kses_hook_priority     = \has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
		try {
			$results = array_merge( $results, self::check_allowed_html_contracts( $seed ) );
			$results = array_merge( $results, self::check_custom_policy_and_filter_invariants( $seed ) );
			$results[] = self::check_generated_policy_protocol_style_matrix( $seed );
			$results[] = self::check_generated_scoped_policy_mutation_matrix( $seed );
			$results[] = self::check_style_protocol_cross_hook_matrix( $seed );
			$results[] = self::check_semicolon_data_url_css_parser_matrix( $seed );
			$results[] = self::check_css_url_protocol_fragment_matrix( $seed );
			$results = array_merge( $results, self::check_attribute_constraint_invariants( $seed ) );
			$results = array_merge( $results, self::check_pdf_object_and_uri_attribute_invariants( $seed ) );
			$results[] = self::check_helper_contract_matrix( $seed );
			$results[] = self::check_no_null_control_matrix( $seed );
			$results[] = self::check_kses_filter_lifecycle_invariants( $seed );
			$results[] = self::check_wp_kses_post_deep_invariants( $seed );
			$results[] = self::check_pre_kses_hook_state(
				$seed,
				'kses.pre_kses-hook-state-before-block-attribute-check',
				$pre_kses_hook_signature,
				$pre_kses_hook_priority
			);
			self::restore_hook( 'pre_kses', $pre_kses_hook_snapshot );
			$results[] = self::check_block_attribute_kses_invariants( $seed );

			$rng = self::rng( $seed );
			for ( $case_index = 0; $case_index < $case_count; ++$case_index ) {
				$case    = self::generate_case( $rng, $case_index );
				$results = array_merge( $results, self::check_case( $seed, $case_index, $case ) );
			}
			$results[] = self::check_pre_kses_hook_state(
				$seed,
				'kses.pre_kses-hook-state-after-surface-run',
				$pre_kses_hook_signature,
				$pre_kses_hook_priority
			);
		} finally {
			self::restore_hook( 'pre_kses', $pre_kses_hook_snapshot );
			self::restore_globals( $global_snapshot );
		}

		$results[] = self::check_global_restoration( $seed, $global_snapshot );

		return $results;
	}

	private static function check_case( int $seed, int $case_index, array $case ): array {
		$results = array();
		$results = array_merge( $results, self::check_wp_kses_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_wp_kses_post_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_wp_kses_data_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_bad_protocol_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_bad_protocol_helper_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_url_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_safecss_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_nohtml_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_entity_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_hair_parse_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_one_attr_invariants( $seed, $case_index, $case ) );

		return $results;
	}

	private static function check_allowed_html_contracts( int $seed ): array {
		$results = array();

		try {
			$contexts = array( 'post', 'data', 'strip', 'entities', 'user_description', 'pre_user_description' );
			$counts   = array();
			foreach ( $contexts as $context ) {
				$allowed = \wp_kses_allowed_html( $context );
				if ( ! is_array( $allowed ) ) {
					$results[] = self::fail(
						$seed,
						null,
						'wp_kses_allowed_html.context-returns-array',
						$context,
						'array',
						gettype( $allowed ),
						array( 'context' => $context )
					);
					continue;
				}
				$counts[ $context ] = count( $allowed );
			}

			if ( ! isset( $results[0] ) ) {
				$results[] = self::pass(
					$seed,
					null,
					'wp_kses_allowed_html.context-returns-array',
					implode( ',', $contexts ),
					array( 'counts' => $counts )
				);
			}

			$strip = \wp_kses_allowed_html( 'strip' );
			if ( array() === $strip ) {
				$results[] = self::pass( $seed, null, 'wp_kses_allowed_html.strip-is-empty-policy', 'strip' );
			} else {
				$results[] = self::fail(
					$seed,
					null,
					'wp_kses_allowed_html.strip-is-empty-policy',
					'strip',
					array(),
					$strip
				);
			}

			foreach ( array( 'post', 'data' ) as $context ) {
				$violations = self::allowed_html_case_violations( \wp_kses_allowed_html( $context ) );
				if ( empty( $violations ) ) {
					$results[] = self::pass(
						$seed,
						null,
						'wp_kses_allowed_html.lowercase-policy-keys',
						$context,
						array( 'context' => $context )
					);
				} else {
					$results[] = self::fail(
						$seed,
						null,
						'wp_kses_allowed_html.lowercase-policy-keys',
						$context,
						'lowercase tag and attribute keys',
						$violations,
						array( 'context' => $context )
					);
				}
			}

			$explicit = array(
				'a' => array(
					'href'  => true,
					'title' => true,
				),
			);
			$actual   = \wp_kses_allowed_html( $explicit );
			if ( $explicit === $actual ) {
				$results[] = self::pass( $seed, null, 'wp_kses_allowed_html.explicit-policy-round-trip', '<a href title>' );
			} else {
				$results[] = self::fail(
					$seed,
					null,
					'wp_kses_allowed_html.explicit-policy-round-trip',
					'<a href title>',
					$explicit,
					$actual
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, null, 'wp_kses_allowed_html.contracts-no-throw', '', $e );
		}

		return $results;
	}

	private static function check_custom_policy_and_filter_invariants( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'apply_filters', 'safecss_filter_attr' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						null,
						'kses.custom-policy-filters.available',
						$function_name . '() is not loaded',
						''
					),
				);
			}
		}

		$results       = array();
		$context       = 'component_fuzz_kses_' . substr( sha1( (string) $seed ), 0, 12 );
		$pre_marker    = 'cf-pre-kses-' . substr( sha1( 'pre:' . $seed ), 0, 8 );
		$custom_policy = self::custom_context_policy();
		$protocols     = array( 'https', 'mailto' );
		$html          = '<mark data-cf="safe" data-cf.bad="drop" class="drop">Custom &amp; text</mark>'
			. '<a href="https://example.com/path" data-cf="link" onclick="evil()">Link</a>'
			. '<strong>Plain</strong><mark data-cf-extra="tail">Tail</mark>';
		$style_probe   = 'text-transform-case-probe:uppercase;color:red';

		$post_before    = \wp_kses_allowed_html( 'post' );
		$data_before    = \wp_kses_allowed_html( 'data' );
		$current_before = $GLOBALS['wp_current_filter'] ?? null;

		$allowed_filter = static function ( $allowed_html, $received_context ) use ( $context, $custom_policy ) {
			if ( $received_context === $context ) {
				return $custom_policy;
			}

			return $allowed_html;
		};
		$pre_filter     = static function ( $content, $allowed_html ) use ( $context, $pre_marker ) {
			if ( $allowed_html === $context ) {
				return $content . '<mark data-cf="pre">' . $pre_marker . '</mark>';
			}

			return $content;
		};
		$style_filter   = static function ( $properties ) {
			$properties[] = 'text-transform-case-probe';
			return $properties;
		};

		\add_filter( 'wp_kses_allowed_html', $allowed_filter, 10, 2 );
		\add_filter( 'pre_kses', $pre_filter, 10, 3 );
		\add_filter( 'safe_style_css', $style_filter, 10, 1 );

		$filtered        = '';
		$filtered_style  = '';
		$policy_during   = null;
		$post_during     = null;
		$data_during     = null;
		$exception       = null;
		$filters_removed = false;

		try {
			$policy_during  = \wp_kses_allowed_html( $context );
			$post_during    = \wp_kses_allowed_html( 'post' );
			$data_during    = \wp_kses_allowed_html( 'data' );
			$filtered       = \wp_kses( $html, $context, $protocols );
			$filtered_style = \safecss_filter_attr( $style_probe );
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			\remove_filter( 'wp_kses_allowed_html', $allowed_filter, 10 );
			\remove_filter( 'pre_kses', $pre_filter, 10 );
			\remove_filter( 'safe_style_css', $style_filter, 10 );
			$filters_removed = false === \has_filter( 'wp_kses_allowed_html', $allowed_filter )
				&& false === \has_filter( 'pre_kses', $pre_filter )
				&& false === \has_filter( 'safe_style_css', $style_filter );
		}

		if ( null !== $exception ) {
			return array(
				self::throwable_result(
					$seed,
					null,
					'kses.custom-policy-and-filter-locality.no-throw',
					$html,
					$exception
				),
			);
		}

		$details = array(
			'context'       => $context,
			'outputPreview' => self::preview( $filtered ),
			'stylePreview'  => self::preview( $filtered_style ),
		);

		if ( $custom_policy === $policy_during ) {
			$results[] = self::pass( $seed, null, 'wp_kses_allowed_html.custom-context-policy-visible', $context, $details );
		} else {
			$results[] = self::fail(
				$seed,
				null,
				'wp_kses_allowed_html.custom-context-policy-visible',
				$context,
				$custom_policy,
				$policy_during,
				$details
			);
		}

		$violations = self::policy_violations( $filtered, $custom_policy, $protocols );
		$violations = array_merge( $violations, self::attribute_boundary_violations( $filtered ) );
		$violations = array_merge( $violations, self::comment_syntax_violations( $filtered ) );
		if (
			empty( $violations )
			&& false !== strpos( $filtered, '<mark data-cf="safe">Custom &amp; text</mark>' )
			&& false !== strpos( $filtered, '<a href="https://example.com/path" data-cf="link">Link</a>' )
			&& false !== strpos( $filtered, $pre_marker )
			&& false === strpos( $filtered, 'onclick' )
			&& false === strpos( $filtered, '<strong' )
		) {
			$results[] = self::pass( $seed, null, 'wp_kses.custom-context-enforces-local-policy', $html, $details );
		} else {
			$results[] = self::fail(
				$seed,
				null,
				'wp_kses.custom-context-enforces-local-policy',
				$html,
				'custom context keeps only its allowed tags/attributes and scoped pre_kses marker',
				array(
					'violations' => $violations,
					'output'     => self::preview( $filtered ),
				),
				$details
			);
		}

		if ( $post_before === $post_during && $data_before === $data_during ) {
			$results[] = self::pass( $seed, null, 'wp_kses_allowed_html.custom-context-does-not-change-builtins', $context, $details );
		} else {
			$results[] = self::fail(
				$seed,
				null,
				'wp_kses_allowed_html.custom-context-does-not-change-builtins',
				$context,
				'builtin post/data policies remain unchanged while custom filter is active',
				array(
					'postChanged' => $post_before !== $post_during,
					'dataChanged' => $data_before !== $data_during,
				),
				$details
			);
		}

		if ( 'text-transform-case-probe:uppercase;color:red' === $filtered_style ) {
			$results[] = self::pass( $seed, null, 'safecss_filter_attr.safe_style_css-filter-locality-active', $style_probe, $details );
		} else {
			$results[] = self::fail(
				$seed,
				null,
				'safecss_filter_attr.safe_style_css-filter-locality-active',
				$style_probe,
				'temporarily filtered property and baseline color rule',
				$filtered_style,
				$details
			);
		}

		$post_after          = \wp_kses_allowed_html( 'post' );
		$data_after          = \wp_kses_allowed_html( 'data' );
		$custom_after        = \wp_kses_allowed_html( $context );
		$style_after         = \safecss_filter_attr( $style_probe );
		$current_after       = $GLOBALS['wp_current_filter'] ?? null;
		$restoration_details = $details + array(
			'filtersRemoved'    => $filters_removed,
			'postRestored'      => $post_before === $post_after,
			'dataRestored'      => $data_before === $data_after,
			'customPolicyGone'  => $custom_after !== $custom_policy,
			'styleAfterPreview' => self::preview( $style_after ),
			'currentRestored'   => $current_before === $current_after,
		);

		if (
			$filters_removed
			&& $post_before === $post_after
			&& $data_before === $data_after
			&& $custom_after !== $custom_policy
			&& 'color:red' === $style_after
			&& $current_before === $current_after
		) {
			$results[] = self::pass( $seed, null, 'kses.custom-filters-restored', $context, $restoration_details );
		} else {
			$results[] = self::fail(
				$seed,
				null,
				'kses.custom-filters-restored',
				$context,
				'custom KSES/CSS filters removed and builtin policies/filter stack restored',
				$restoration_details,
				$restoration_details
			);
		}

		return $results;
	}

	private static function check_generated_policy_protocol_style_matrix( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'safecss_filter_attr', 'wp_kses' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.generated-policy-protocol-style-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng             = self::rng( self::normalize_seed( 'kses-policy-style:' . $seed ) );
		$custom_property = 'component-fuzz-css-' . substr( sha1( 'safe-style:' . $seed ), 0, 8 );
		$style_probe     = 'color:red;' . $custom_property . ':uppercase;background-image:url(javascript:alert(1));foo:bar';
		$style_filter    = static function ( $properties ) use ( $custom_property ) {
			if ( is_array( $properties ) ) {
				$properties[] = $custom_property;
			}

			return $properties;
		};

		$protocol_profiles = array(
			array( 'https', 'mailto' ),
			array( 'http', 'https' ),
			array( 'https', 'ftp' ),
			array( 'mailto' ),
			array( 'https' ),
		);
		$unsafe_urls       = array(
			'javascript:alert(1)',
			'java&#x0a;script:alert(1)',
			'vbscript:msgbox(1)',
			'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
		);
		$wrappers          = array( 'div', 'section', 'mark' );
		$failures          = array();
		$case_labels       = array();
		$filtered_css      = '';
		$filters_removed   = false;
		$exception         = null;

		\add_filter( 'safe_style_css', $style_filter, 10, 1 );
		try {
			$filtered_css = \safecss_filter_attr( $style_probe );
			if (
				false === strpos( $filtered_css, 'color:red' )
				|| false === strpos( $filtered_css, $custom_property . ':uppercase' )
				|| ! empty( self::css_policy_violations( $filtered_css, array( 'https' ) ) )
			) {
				$failures[] = array(
					'label'       => 'temporary safe_style_css property is active and still strips unsafe CSS',
					'input'       => self::preview( $style_probe ),
					'output'      => self::preview( $filtered_css ),
					'violations'  => self::css_policy_violations( $filtered_css, array( 'https' ) ),
					'customProp'  => $custom_property,
				);
			}

			for ( $case_index = 0; $case_index < 12; ++$case_index ) {
				$token          = substr( sha1( $seed . ':policy-style:' . $case_index . ':' . self::rng_uint32( $rng ) ), 0, 8 );
				$protocols      = self::rng_choice( $rng, $protocol_profiles );
				$safe_scheme    = self::rng_choice( $rng, $protocols );
				$safe_url       = 'mailto' === $safe_scheme
					? 'mailto:reader-' . $token . '@example.test'
					: $safe_scheme . '://example.test/component-fuzz-' . $token;
				$unsafe_url     = self::rng_choice( $rng, $unsafe_urls );
				$allow_style    = self::rng_chance( $rng, 65 );
				$allow_img      = self::rng_chance( $rng, 50 );
				$allow_cite     = self::rng_chance( $rng, 50 );
				$wrapper        = self::rng_choice( $rng, $wrappers );
				$block_attrs    = array(
					'class'  => true,
					'data-*' => true,
				);
				$inline_attrs   = array(
					'data-*' => true,
					'title'  => true,
				);
				if ( $allow_style ) {
					$block_attrs['style']  = true;
					$inline_attrs['style'] = true;
				}

				$policy = array(
					'a'       => array(
						'href'   => true,
						'title'  => true,
						'data-*' => true,
					),
					'br'      => array(),
					'div'     => $block_attrs,
					'mark'    => $inline_attrs,
					'p'       => $block_attrs,
					'section' => $block_attrs,
					'span'    => $inline_attrs,
				);
				if ( $allow_img ) {
					$policy['img'] = array(
						'alt'    => true,
						'data-*' => true,
						'src'    => true,
					);
				}
				if ( $allow_cite ) {
					$policy['blockquote'] = array(
						'cite'   => true,
						'data-*' => true,
					);
				}

				$wide_block_attrs = array(
					'class'  => true,
					'data-*' => true,
					'style'  => true,
					'title'  => true,
				);
				$wide_inline_attrs = array(
					'class'  => true,
					'data-*' => true,
					'style'  => true,
					'title'  => true,
				);
				$wide_policy      = $policy + array(
					'blockquote' => array(
						'cite'   => true,
						'data-*' => true,
					),
					'code'       => array(),
					'em'         => array(),
					'img'        => array(
						'alt'    => true,
						'data-*' => true,
						'src'    => true,
					),
					'strong'     => array(),
				);
				foreach ( array( 'div', 'p', 'section' ) as $tag_name ) {
					$wide_policy[ $tag_name ] = $wide_block_attrs;
				}
				foreach ( array( 'mark', 'span' ) as $tag_name ) {
					$wide_policy[ $tag_name ] = $wide_inline_attrs;
				}

				$style = 'color:red;' . $custom_property . ':uppercase;background-image:url(' . $unsafe_url . ');foo:bar';
				$html  = '<!-- wp:paragraph {"componentFuzz":"' . $token . '"} -->'
					. '<' . $wrapper . ' class="cf-' . $token . '" data-cf="' . $token . '" style="' . $style . '" onclick="evil()">'
					. '<a href="' . $safe_url . '" title="safe-' . $token . '" data-cf="' . $token . '">safe link</a>'
					. '<a href="' . $unsafe_url . '" data-cf="bad" onclick="evil()">bad link</a>'
					. '<span data-cf="' . $token . '" data-cf.bad="drop" title="ok">span</span>'
					. '<img src="' . $safe_url . '" alt="image-' . $token . '" onerror="evil">'
					. '<blockquote cite="' . $unsafe_url . '">quote</blockquote>'
					. '<!-- malformed -- comment --><script>alert(1)</script><strong>tail</strong>'
					. '</' . $wrapper . '><!-- /wp:paragraph -->';

				$filtered       = \wp_kses( $html, $policy, $protocols );
				$refiltered     = \wp_kses( $filtered, $policy, $protocols );
				$widened_filter = \wp_kses( $filtered, $wide_policy, $protocols );
				$violations     = array_merge(
					self::policy_violations( $filtered, $policy, $protocols ),
					self::attribute_boundary_violations( $filtered ),
					self::comment_syntax_violations( $filtered ),
					self::style_attribute_violations( $filtered, $protocols )
				);
				$case_failures  = array();

				if ( $filtered !== $refiltered ) {
					$case_failures[] = array(
						'type'       => 'idempotence',
						'filtered'   => self::preview( $filtered ),
						'refiltered' => self::preview( $refiltered ),
					);
				}
				if ( $filtered !== $widened_filter ) {
					$case_failures[] = array(
						'type'     => 'widened-policy-stability',
						'filtered' => self::preview( $filtered ),
						'widened'  => self::preview( $widened_filter ),
					);
				}
				if ( ! empty( $violations ) ) {
					$case_failures[] = array(
						'type'       => 'structural-violations',
						'violations' => array_slice( $violations, 0, 6 ),
						'output'     => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, 'href="' . $safe_url . '"' ) ) {
					$case_failures[] = array(
						'type'    => 'safe-href-stripped',
						'href'    => $safe_url,
						'output'  => self::preview( $filtered ),
					);
				}
				if ( false !== stripos( $filtered, 'onclick' ) || false !== stripos( $filtered, 'onerror' ) || false !== stripos( $filtered, '<script' ) ) {
					$case_failures[] = array(
						'type'   => 'event-or-script-token-survived',
						'output' => self::preview( $filtered ),
					);
				}
				if ( ! $allow_style && ! empty( self::style_attribute_values( $filtered ) ) ) {
					$case_failures[] = array(
						'type'   => 'style-attribute-survived-disallowed-policy',
						'styles' => self::style_attribute_values( $filtered ),
					);
				}
				if (
					$allow_style
					&& (
						false === strpos( $filtered, 'color:red' )
						|| false === strpos( $filtered, $custom_property . ':uppercase' )
					)
				) {
					$case_failures[] = array(
						'type'       => 'allowed-safe-style-not-preserved',
						'style'      => $style,
						'output'     => self::preview( $filtered ),
						'allowStyle' => $allow_style,
					);
				}

				$case_labels[] = array(
					'token'      => $token,
					'protocols'  => $protocols,
					'style'      => $allow_style,
					'img'        => $allow_img,
					'blockquote' => $allow_cite,
				);
				if ( ! empty( $case_failures ) ) {
					$failures[] = array(
						'case'     => $case_index,
						'label'    => end( $case_labels ),
						'failures' => $case_failures,
					);
				}
			}
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			\remove_filter( 'safe_style_css', $style_filter, 10 );
			$filters_removed = false === \has_filter( 'safe_style_css', $style_filter );
		}

		if ( null !== $exception ) {
			return self::throwable_result(
				$seed,
				null,
				'kses.generated-policy-protocol-style-matrix.no-throw',
				$style_probe,
				$exception
			);
		}

		$css_after = \safecss_filter_attr( $style_probe );
		if (
			! $filters_removed
			|| false !== strpos( $css_after, $custom_property )
			|| ! empty( self::css_policy_violations( $css_after, array( 'https' ) ) )
		) {
			$failures[] = array(
				'label'          => 'temporary safe_style_css property is removed after generated matrix',
				'filtersRemoved' => $filters_removed,
				'cssAfter'       => self::preview( $css_after ),
				'violations'     => self::css_policy_violations( $css_after, array( 'https' ) ),
			);
		}

		$details = array(
			'caseCount'       => count( $case_labels ),
			'customProperty'  => $custom_property,
			'filteredCss'     => self::preview( $filtered_css ),
			'filteredCssPost' => self::preview( $css_after ),
			'filtersRemoved'  => $filters_removed,
			'cases'           => array_slice( $case_labels, 0, 6 ),
			'failureCount'    => count( $failures ),
			'failures'        => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.generated-policy-protocol-style-matrix', $custom_property, $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.generated-policy-protocol-style-matrix',
			$custom_property,
			'generated policies remain idempotent, monotonic under wider policies, protocol-safe, style-filtered, and comment-boundary safe',
			$failures,
			$details
		);
	}

	private static function check_generated_scoped_policy_mutation_matrix( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'safecss_filter_attr', 'wp_kses', 'wp_kses_allowed_html', 'wp_kses_uri_attributes' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.generated-scoped-policy-mutation-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng             = self::rng( self::normalize_seed( 'kses-scoped-policy:' . $seed ) );
		$policy_prefix   = 'component_fuzz_kses_scoped_' . substr( sha1( 'policy:' . $seed ), 0, 10 );
		$custom_property = 'component-fuzz-policy-' . substr( sha1( 'css:' . $seed ), 0, 8 );
		$custom_uri_attr = 'data-route';
		$policies        = array();
		$failures        = array();
		$case_labels     = array();
		$filter_hits     = array(
			'allowedHtml'   => 0,
			'uriAttributes' => 0,
			'safeStyleCss'  => 0,
		);
		$style_probe     = 'color:red;' . $custom_property . ':uppercase;background-image:url(javascript:alert(1));foo:bar';
		$post_before     = \wp_kses_allowed_html( 'post' );
		$data_before     = \wp_kses_allowed_html( 'data' );
		$uri_before      = \wp_kses_uri_attributes();
		$hook_snapshot   = self::snapshot_hooks( array( 'wp_kses_allowed_html', 'wp_kses_uri_attributes', 'safe_style_css' ) );
		$exception       = null;

		$allowed_filter = static function ( $allowed_html, $context ) use ( &$filter_hits, &$policies ) {
			++$filter_hits['allowedHtml'];
			if ( is_string( $context ) && isset( $policies[ $context ] ) ) {
				return $policies[ $context ];
			}

			return $allowed_html;
		};
		$uri_filter     = static function ( $attributes ) use ( &$filter_hits, $custom_uri_attr ) {
			++$filter_hits['uriAttributes'];
			if ( is_array( $attributes ) ) {
				$attributes[] = $custom_uri_attr;
			}

			return $attributes;
		};
		$style_filter   = static function ( $properties ) use ( &$filter_hits, $custom_property ) {
			++$filter_hits['safeStyleCss'];
			if ( is_array( $properties ) ) {
				$properties[] = $custom_property;
			}

			return $properties;
		};

		\add_filter( 'wp_kses_allowed_html', $allowed_filter, 10, 2 );
		\add_filter( 'wp_kses_uri_attributes', $uri_filter, 10, 1 );
		\add_filter( 'safe_style_css', $style_filter, 10, 1 );

		try {
			$protocol_profiles = array(
				array( 'https' ),
				array( 'https', 'mailto' ),
				array( 'http', 'https' ),
				array( 'https', 'ftp' ),
			);
			$wrappers          = array( 'article', 'aside', 'section', 'div' );
			$uri_cases         = array(
				array( 'safeTag' => 'form', 'safeAttr' => 'action', 'unsafeTag' => 'button', 'unsafeAttr' => 'formaction' ),
				array( 'safeTag' => 'q', 'safeAttr' => 'cite', 'unsafeTag' => 'blockquote', 'unsafeAttr' => 'cite' ),
				array( 'safeTag' => 'video', 'safeAttr' => 'poster', 'unsafeTag' => 'a', 'unsafeAttr' => 'href' ),
				array( 'safeTag' => 'div', 'safeAttr' => 'background', 'unsafeTag' => 'span', 'unsafeAttr' => $custom_uri_attr ),
			);
			$unsafe_urls       = array(
				'javascript:alert(1)',
				'java&#x0a;script:alert(1)',
				'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
				'vbscript:msgbox(1)',
			);

			$filtered_css = \safecss_filter_attr( $style_probe );
			if (
				false === strpos( $filtered_css, 'color:red' )
				|| false === strpos( $filtered_css, $custom_property . ':uppercase' )
				|| ! empty( self::css_policy_violations( $filtered_css, array( 'https' ) ) )
			) {
				$failures[] = array(
					'type'        => 'safe-style-css-filter-not-active',
					'output'      => self::preview( $filtered_css ),
					'violations'  => self::css_policy_violations( $filtered_css, array( 'https' ) ),
					'customProp'  => $custom_property,
				);
			}

			for ( $case_index = 0; $case_index < 10; ++$case_index ) {
				$token        = substr( sha1( $seed . ':scoped-policy:' . $case_index . ':' . self::rng_uint32( $rng ) ), 0, 8 );
				$context      = $policy_prefix . '_' . $case_index;
				$protocols    = self::rng_choice( $rng, $protocol_profiles );
				$wrapper      = self::rng_choice( $rng, $wrappers );
				$uri_case     = self::rng_choice( $rng, $uri_cases );
				$allow_style  = self::rng_chance( $rng, 65 );
				$allow_img    = self::rng_chance( $rng, 55 );
				$allow_em     = self::rng_chance( $rng, 50 );
				$safe_url     = 'https://example.test/kses/' . $token;
				$mail_url     = 'mailto:reader-' . $token . '@example.test';
				$route_url    = 'https://example.test/route/' . $token;
				$unsafe_url   = self::rng_choice( $rng, $unsafe_urls );
				$style        = 'color:red;' . $custom_property . ':uppercase;background-image:url(' . $unsafe_url . ');foo:bar';
				$data_style   = 'color:red;' . $custom_property . ':uppercase';
				$block_attrs  = array(
					'class'           => true,
					'data-*'          => true,
					$custom_uri_attr  => true,
				);
				$inline_attrs = array(
					'data-*'          => true,
					'title'           => true,
					$custom_uri_attr  => true,
				);

				if ( $allow_style ) {
					$block_attrs['style']  = true;
					$inline_attrs['style'] = true;
				}

				$policy = array(
					$wrapper => $block_attrs,
					'a'      => array(
						'href'          => true,
						'title'         => true,
						'data-*'        => true,
						$custom_uri_attr => true,
					),
					'br'     => array(),
					'span'   => $inline_attrs,
				);

				if ( $allow_img ) {
					$policy['img'] = array(
						'alt'            => true,
						'data-*'         => true,
						'src'            => true,
						$custom_uri_attr => true,
					);
				}
				if ( $allow_em ) {
					$policy['em'] = array(
						'data-*' => true,
					);
				}
				foreach ( array( $uri_case['safeTag'], $uri_case['unsafeTag'] ) as $tag_name ) {
					if ( ! isset( $policy[ $tag_name ] ) ) {
						$policy[ $tag_name ] = array( 'data-*' => true );
					}
				}
				$policy[ $uri_case['safeTag'] ][ $uri_case['safeAttr'] ]     = true;
				$policy[ $uri_case['unsafeTag'] ][ $uri_case['unsafeAttr'] ] = true;

				$policies[ $context ] = $policy;
				$html                 = '<' . $wrapper . ' class="cf-' . $token . '" data-token="' . $token . '" ' . $custom_uri_attr . '="' . $route_url . '" style="' . $style . '" onclick="evil()">'
					. '<a href="' . $safe_url . '" ' . $custom_uri_attr . '="' . $route_url . '" title="safe-' . $token . '" data-token="' . $token . '">safe</a>'
					. '<a href="' . $unsafe_url . '" ' . $custom_uri_attr . '="' . $unsafe_url . '" onclick="evil()">bad</a>'
					. '<span data-token="' . $token . '" data-style="' . $data_style . '" data-token.bad="drop" title="ok" style="' . $style . '">span<em data-token="' . $token . '">em</em><strong>strong</strong></span>'
					. '<img src="' . $safe_url . '" ' . $custom_uri_attr . '="' . $unsafe_url . '" alt="image-' . $token . '" onerror="evil">'
					. '<' . $uri_case['safeTag'] . ' ' . $uri_case['safeAttr'] . '="' . $safe_url . '" data-token="' . $token . '">uri-safe</' . $uri_case['safeTag'] . '>'
					. '<' . $uri_case['unsafeTag'] . ' ' . $uri_case['unsafeAttr'] . '="' . $unsafe_url . '" data-token="' . $token . '">uri-bad</' . $uri_case['unsafeTag'] . '>'
					. '<button formaction="' . $safe_url . '">button</button><!-- bad -- comment --><script>alert(1)</script>'
					. '</' . $wrapper . '>';

				if ( in_array( 'mailto', $protocols, true ) ) {
					$html .= '<a href="' . $mail_url . '" data-token="' . $token . '">mail</a>';
				}

				$context_policy = \wp_kses_allowed_html( $context );
				$filtered       = \wp_kses( $html, $context, $protocols );
				$direct         = \wp_kses( $html, $policy, $protocols );
				$refiltered     = \wp_kses( $filtered, $context, $protocols );
				$violations     = array_merge(
					self::policy_violations( $filtered, $policy, $protocols ),
					self::attribute_boundary_violations( $filtered ),
					self::comment_syntax_violations( $filtered ),
					self::style_attribute_violations( $filtered, $protocols )
				);
				$case_failures  = array();

				if ( $context_policy !== $policy ) {
					$case_failures[] = array(
						'type'     => 'context-policy-mismatch',
						'expected' => $policy,
						'actual'   => $context_policy,
					);
				}
				if ( $filtered !== $direct ) {
					$case_failures[] = array(
						'type'     => 'context-vs-direct-policy-output',
						'context'  => self::preview( $filtered ),
						'direct'   => self::preview( $direct ),
					);
				}
				if ( $filtered !== $refiltered ) {
					$case_failures[] = array(
						'type'       => 'idempotence',
						'filtered'   => self::preview( $filtered ),
						'refiltered' => self::preview( $refiltered ),
					);
				}
				if ( ! empty( $violations ) ) {
					$case_failures[] = array(
						'type'       => 'policy-violations',
						'violations' => array_slice( $violations, 0, 6 ),
						'output'     => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, 'href="' . $safe_url . '"' ) ) {
					$case_failures[] = array(
						'type'   => 'safe-href-stripped',
						'url'    => $safe_url,
						'output' => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, $custom_uri_attr . '="' . $route_url . '"' ) ) {
					$case_failures[] = array(
						'type'   => 'safe-custom-uri-attribute-stripped',
						'attr'   => $custom_uri_attr,
						'url'    => $route_url,
						'output' => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, $uri_case['safeAttr'] . '="' . $safe_url . '"' ) ) {
					$case_failures[] = array(
						'type'    => 'safe-generated-uri-attribute-stripped',
						'tag'     => $uri_case['safeTag'],
						'attr'    => $uri_case['safeAttr'],
						'url'     => $safe_url,
						'output'  => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, 'data-style="' . $data_style . '"' ) ) {
					$case_failures[] = array(
						'type'      => 'style-like-data-attribute-stripped',
						'dataStyle' => $data_style,
						'output'    => self::preview( $filtered ),
					);
				}
				if ( false !== stripos( $filtered, '<script' ) || 1 === preg_match( '/\son[a-z0-9_-]*\s*=/i', $filtered ) ) {
					$case_failures[] = array(
						'type'   => 'dangerous-token-survived',
						'output' => self::preview( $filtered ),
					);
				}
				if (
					false !== strpos( $filtered, 'href="' . $unsafe_url . '"' )
					|| false !== strpos( $filtered, $custom_uri_attr . '="' . $unsafe_url . '"' )
					|| false !== strpos( $filtered, $uri_case['unsafeAttr'] . '="' . $unsafe_url . '"' )
				) {
					$case_failures[] = array(
						'type'      => 'unsafe-uri-attribute-preserved',
						'unsafeUrl' => $unsafe_url,
						'output'    => self::preview( $filtered ),
					);
				}
				if ( ! $allow_img && false !== stripos( $filtered, '<img' ) ) {
					$case_failures[] = array(
						'type'   => 'image-survived-disallowed-policy',
						'output' => self::preview( $filtered ),
					);
				}
				if ( ! $allow_em && false !== stripos( $filtered, '<em' ) ) {
					$case_failures[] = array(
						'type'   => 'em-survived-disallowed-policy',
						'output' => self::preview( $filtered ),
					);
				}
				if ( ! $allow_style && ! empty( self::style_attribute_values( $filtered ) ) ) {
					$case_failures[] = array(
						'type'   => 'style-survived-disallowed-policy',
						'styles' => self::style_attribute_values( $filtered ),
					);
				}
				if (
					$allow_style
					&& (
						false === strpos( $filtered, 'color:red' )
						|| false === strpos( $filtered, $custom_property . ':uppercase' )
					)
				) {
					$case_failures[] = array(
						'type'   => 'safe-style-stripped',
						'output' => self::preview( $filtered ),
					);
				}

				$case_labels[] = array(
					'context'    => $context,
					'protocols'  => $protocols,
					'wrapper'    => $wrapper,
					'uriCase'    => $uri_case['safeTag'] . '.' . $uri_case['safeAttr'] . '/' . $uri_case['unsafeTag'] . '.' . $uri_case['unsafeAttr'],
					'style'      => $allow_style,
					'img'        => $allow_img,
					'em'         => $allow_em,
				);
				if ( ! empty( $case_failures ) ) {
					$failures[] = array(
						'case'     => $case_index,
						'label'    => end( $case_labels ),
						'failures' => $case_failures,
					);
				}
			}

			if ( $post_before !== \wp_kses_allowed_html( 'post' ) || $data_before !== \wp_kses_allowed_html( 'data' ) ) {
				$failures[] = array(
					'type'        => 'builtin-policy-mutated-while-filters-active',
					'postChanged' => $post_before !== \wp_kses_allowed_html( 'post' ),
					'dataChanged' => $data_before !== \wp_kses_allowed_html( 'data' ),
				);
			}
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			\remove_filter( 'wp_kses_allowed_html', $allowed_filter, 10 );
			\remove_filter( 'wp_kses_uri_attributes', $uri_filter, 10 );
			\remove_filter( 'safe_style_css', $style_filter, 10 );
			self::restore_hooks( $hook_snapshot );
		}

		if ( null !== $exception ) {
			return self::throwable_result(
				$seed,
				null,
				'kses.generated-scoped-policy-mutation-matrix.no-throw',
				$policy_prefix,
				$exception
			);
		}

		$filters_removed = false === \has_filter( 'wp_kses_allowed_html', $allowed_filter )
			&& false === \has_filter( 'wp_kses_uri_attributes', $uri_filter )
			&& false === \has_filter( 'safe_style_css', $style_filter );
		$css_after       = \safecss_filter_attr( $style_probe );
		$uri_after       = \wp_kses_uri_attributes();
		$custom_context_after = \wp_kses_allowed_html( $policy_prefix . '_0' );
		if (
			! $filters_removed
			|| false !== strpos( $css_after, $custom_property )
			|| in_array( $custom_uri_attr, self::lowercase_list( is_array( $uri_after ) ? $uri_after : array() ), true )
			|| $custom_context_after === ( $policies[ $policy_prefix . '_0' ] ?? null )
			|| $post_before !== \wp_kses_allowed_html( 'post' )
			|| $data_before !== \wp_kses_allowed_html( 'data' )
			|| $uri_before !== $uri_after
		) {
			$failures[] = array(
				'type'                 => 'filter-restoration',
				'filtersRemoved'       => $filters_removed,
				'cssAfter'             => self::preview( $css_after ),
				'uriRestored'          => $uri_before === $uri_after,
				'customUriPresent'     => in_array( $custom_uri_attr, self::lowercase_list( is_array( $uri_after ) ? $uri_after : array() ), true ),
				'customContextRemoved' => $custom_context_after !== ( $policies[ $policy_prefix . '_0' ] ?? null ),
				'postRestored'         => $post_before === \wp_kses_allowed_html( 'post' ),
				'dataRestored'         => $data_before === \wp_kses_allowed_html( 'data' ),
			);
		}

		$details = array(
			'caseCount'      => count( $case_labels ),
			'policyPrefix'   => $policy_prefix,
			'customProperty' => $custom_property,
			'customUriAttr'  => $custom_uri_attr,
			'filterHits'     => $filter_hits,
			'filtersRemoved' => $filters_removed,
			'cases'          => array_slice( $case_labels, 0, 6 ),
			'failureCount'   => count( $failures ),
			'failures'       => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.generated-scoped-policy-mutation-matrix', $policy_prefix, $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.generated-scoped-policy-mutation-matrix',
			$policy_prefix,
			'generated context policies, URI attributes, and safe CSS filters remain scoped while enforcing allow/deny oracles',
			$failures,
			$details
		);
	}

	private static function check_style_protocol_cross_hook_matrix( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'safecss_filter_attr', 'wp_kses', 'wp_kses_hair', 'wp_kses_attr', 'wp_kses_uri_attributes' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.style-protocol-cross-hook-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng             = self::rng( self::normalize_seed( 'kses-style-protocol-cross-hook:' . $seed ) );
		$custom_uri_attr = 'data-style-url';
		$added_css_prop  = 'list-style-image';
		$protocols       = array( 'https' );
		$hook_names      = array( 'safe_style_css', 'safecss_filter_attr_allow_css', 'wp_kses_uri_attributes' );
		$hook_snapshot   = self::snapshot_hooks( $hook_names );
		$hook_before     = array();
		foreach ( $hook_names as $hook_name ) {
			$hook_before[ $hook_name ] = self::hook_signature( $hook_name );
		}

		$filter_hits = array(
			'safeStyleCss' => 0,
			'allowCss'     => array(),
			'uriAttrs'     => 0,
		);
		$failures    = array();
		$cases       = array();
		$exception   = null;

		$style_filter = static function ( $properties ) use ( &$filter_hits, $added_css_prop ) {
			++$filter_hits['safeStyleCss'];
			if ( is_array( $properties ) ) {
				$properties[] = $added_css_prop;
			}

			return $properties;
		};
		$allow_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$filter_hits ): bool {
			$filter_hits['allowCss'][] = array(
				'default' => $allow_css,
				'test'    => $css_test_string,
			);
			return true;
		};
		$uri_filter   = static function ( array $attributes ) use ( &$filter_hits, $custom_uri_attr ): array {
			++$filter_hits['uriAttrs'];
			$attributes[] = $custom_uri_attr;
			return array_values( array_unique( $attributes ) );
		};

		\add_filter( 'safe_style_css', $style_filter, 10, 1 );
		\add_filter( 'safecss_filter_attr_allow_css', $allow_filter, 10, 2 );
		\add_filter( 'wp_kses_uri_attributes', $uri_filter, 10, 1 );

		try {
			$unsafe_urls = array(
				'javascript:evil',
				'vbscript:evil',
				'livescript:evil',
				'mocha:evil',
			);
			$policy      = array(
				'a'    => array(
					'href'           => true,
					$custom_uri_attr => true,
				),
				'div'  => array(
					'data-*'         => true,
					'style'          => true,
					$custom_uri_attr => true,
				),
				'span' => array(
					'data-*'         => true,
					'style'          => true,
					$custom_uri_attr => true,
				),
			);

			for ( $case_index = 0; $case_index < 8; ++$case_index ) {
				$token        = substr( sha1( $seed . ':style-protocol:' . $case_index . ':' . self::rng_uint32( $rng ) ), 0, 8 );
				$safe_url     = 'https://example.test/kses/' . $token . '.png';
				$route_url    = 'https://example.test/route/' . $token;
				$unsafe_url   = self::rng_choice( $rng, $unsafe_urls );
				$custom_prop  = '--cf-url-' . $token;
				$css          = 'background-image:url(' . $safe_url . ')'
					. ';background:url(' . $unsafe_url . ')'
					. ';' . $added_css_prop . ':url("' . $safe_url . '?list=1")'
					. ';' . $added_css_prop . ':url(' . $unsafe_url . ')'
					. ';' . $custom_prop . ':url(' . $safe_url . '?var=1)'
					. ';' . $custom_prop . '-bad:url(' . $unsafe_url . ')'
					. ';cursor:url(' . $unsafe_url . '), auto'
					. ';color:red';
				$case_failures = array();

				$filtered_css = \safecss_filter_attr( $css );
				$css_violations = self::css_policy_violations( $filtered_css, $protocols );
				if ( ! empty( $css_violations ) ) {
					$case_failures[] = array(
						'type'       => 'direct-css-violations',
						'violations' => array_slice( $css_violations, 0, 6 ),
						'css'        => self::preview( $filtered_css ),
					);
				}
				foreach ( array( $safe_url, $safe_url . '?list=1', $safe_url . '?var=1' ) as $expected_url ) {
					if ( false === strpos( $filtered_css, $expected_url ) ) {
						$case_failures[] = array(
							'type'   => 'safe-css-url-stripped',
							'url'    => $expected_url,
							'css'    => self::preview( $filtered_css ),
						);
					}
				}
				if ( false !== strpos( self::decode_attribute_text( $filtered_css ), self::decode_attribute_text( $unsafe_url ) ) ) {
					$case_failures[] = array(
						'type'      => 'unsafe-css-url-preserved',
						'unsafeUrl' => $unsafe_url,
						'css'       => self::preview( $filtered_css ),
					);
				}

				$html     = '<div style="' . $css . '" ' . $custom_uri_attr . '="' . $route_url . '" data-style-token="' . $token . '">'
					. '<a href="' . $unsafe_url . '" ' . $custom_uri_attr . '="' . $route_url . '">bad-link</a>'
					. '<span style="' . $css . '" ' . $custom_uri_attr . '="' . $unsafe_url . '" data-style-token="' . $token . '">span</span>'
					. '</div>';
				$filtered = \wp_kses( $html, $policy, $protocols );
				$refiltered = \wp_kses( $filtered, $policy, $protocols );
				$html_violations = array_merge(
					self::policy_violations( $filtered, $policy, $protocols ),
					self::attribute_boundary_violations( $filtered ),
					self::style_attribute_violations( $filtered, $protocols )
				);
				if ( $filtered !== $refiltered ) {
					$case_failures[] = array(
						'type'       => 'html-idempotence',
						'filtered'   => self::preview( $filtered ),
						'refiltered' => self::preview( $refiltered ),
					);
				}
				if ( ! empty( $html_violations ) ) {
					$case_failures[] = array(
						'type'       => 'html-policy-violations',
						'violations' => array_slice( $html_violations, 0, 6 ),
						'html'       => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, $custom_uri_attr . '="' . $route_url . '"' ) ) {
					$case_failures[] = array(
						'type'   => 'safe-dynamic-uri-attribute-stripped',
						'attr'   => $custom_uri_attr,
						'url'    => $route_url,
						'html'   => self::preview( $filtered ),
					);
				}
				if ( false === strpos( $filtered, 'data-style-token="' . $token . '"' ) ) {
					$case_failures[] = array(
						'type'   => 'non-uri-data-attribute-stripped',
						'html'   => self::preview( $filtered ),
					);
				}
				if ( false !== strpos( self::decode_attribute_text( $filtered ), self::decode_attribute_text( $unsafe_url ) ) ) {
					$case_failures[] = array(
						'type'      => 'unsafe-html-url-preserved',
						'unsafeUrl' => $unsafe_url,
						'html'      => self::preview( $filtered ),
					);
				}

				$attrs = 'href="' . $unsafe_url . '" ' . $custom_uri_attr . '="' . $unsafe_url . '" data-plain="' . $unsafe_url . '" src="' . $safe_url . '"';
				$hair  = \wp_kses_hair( $attrs, $protocols );
				if ( $unsafe_url === ( $hair['href']['value'] ?? null ) || $unsafe_url === ( $hair[ $custom_uri_attr ]['value'] ?? null ) ) {
					$case_failures[] = array(
						'type'  => 'hair-uri-attribute-not-filtered',
						'hair'  => self::compact_value( $hair ),
						'attrs' => $attrs,
					);
				}
				if ( $unsafe_url !== ( $hair['data-plain']['value'] ?? null ) ) {
					$case_failures[] = array(
						'type'  => 'hair-non-uri-data-attribute-filtered',
						'hair'  => self::compact_value( $hair ),
						'attrs' => $attrs,
					);
				}

				$attr_filtered = \wp_kses_attr(
					'div',
					' style="' . $css . '" ' . $custom_uri_attr . '="' . $unsafe_url . '" data-style-token="' . $token . '"',
					$policy,
					$protocols
				);
				if (
					false !== strpos( self::decode_attribute_text( $attr_filtered ), self::decode_attribute_text( $unsafe_url ) )
					|| ! empty( self::style_attribute_violations( $attr_filtered, $protocols ) )
				) {
					$case_failures[] = array(
						'type'         => 'wp-kses-attr-cross-hook-violation',
						'attrFiltered' => self::preview( $attr_filtered ),
					);
				}

				$cases[] = array(
					'token'       => $token,
					'unsafeUrl'   => self::preview( $unsafe_url, 80 ),
					'filteredCss' => self::preview( $filtered_css, 120 ),
				);
				if ( ! empty( $case_failures ) ) {
					$failures[] = array(
						'case'     => $case_index,
						'token'    => $token,
						'failures' => $case_failures,
					);
				}
			}
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			\remove_filter( 'safe_style_css', $style_filter, 10 );
			\remove_filter( 'safecss_filter_attr_allow_css', $allow_filter, 10 );
			\remove_filter( 'wp_kses_uri_attributes', $uri_filter, 10 );
			self::restore_hooks( $hook_snapshot );
		}

		if ( null !== $exception ) {
			return self::throwable_result(
				$seed,
				null,
				'kses.style-protocol-cross-hook-matrix.no-throw',
				$custom_uri_attr,
				$exception
			);
		}

		$hook_after = array();
		foreach ( $hook_names as $hook_name ) {
			$hook_after[ $hook_name ] = self::hook_signature( $hook_name );
		}
		$filters_removed = false === \has_filter( 'safe_style_css', $style_filter )
			&& false === \has_filter( 'safecss_filter_attr_allow_css', $allow_filter )
			&& false === \has_filter( 'wp_kses_uri_attributes', $uri_filter );
		$css_after = \safecss_filter_attr( $added_css_prop . ':url(https://example.test/after.png);color:red' );
		$uri_after = \wp_kses_uri_attributes();
		if (
			! $filters_removed
			|| $hook_before !== $hook_after
			|| false !== strpos( $css_after, $added_css_prop )
			|| in_array( $custom_uri_attr, self::lowercase_list( is_array( $uri_after ) ? $uri_after : array() ), true )
		) {
			$failures[] = array(
				'type'                 => 'filter-restoration',
				'filtersRemoved'       => $filters_removed,
				'hooksRestored'        => $hook_before === $hook_after,
				'cssAfter'             => self::preview( $css_after ),
				'customUriAttrPresent' => in_array( $custom_uri_attr, self::lowercase_list( is_array( $uri_after ) ? $uri_after : array() ), true ),
				'hookBefore'           => $hook_before,
				'hookAfter'            => $hook_after,
			);
		}
		foreach ( $filter_hits['allowCss'] as $hit ) {
			$decoded_test = self::decode_attribute_text( $hit['test'] );
			foreach ( self::DANGEROUS_PROTOCOLS as $protocol ) {
				if ( false !== stripos( $decoded_test, $protocol . ':' ) ) {
					$failures[] = array(
						'type'     => 'allow-css-filter-saw-unfiltered-dangerous-url',
						'protocol' => $protocol,
						'hit'      => $hit,
					);
					break 2;
				}
			}
		}

		$details = array(
			'caseCount'      => count( $cases ),
			'customUriAttr'  => $custom_uri_attr,
			'addedCssProp'   => $added_css_prop,
			'filterHits'     => array(
				'safeStyleCss' => $filter_hits['safeStyleCss'],
				'allowCss'     => count( $filter_hits['allowCss'] ),
				'uriAttrs'     => $filter_hits['uriAttrs'],
			),
			'filtersRemoved' => $filters_removed,
			'cssAfter'       => self::preview( $css_after ),
			'cases'          => array_slice( $cases, 0, 5 ),
			'failureCount'   => count( $failures ),
			'failures'       => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.style-protocol-cross-hook-matrix', $custom_uri_attr, $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.style-protocol-cross-hook-matrix',
			$custom_uri_attr,
			'CSS URL protocol checks run before permissive CSS allow filters, dynamic URI attributes are protocol-filtered, non-URI data attributes remain data-only, and hooks restore',
			$failures,
			$details
		);
	}

	private static function check_semicolon_data_url_css_parser_matrix( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'safecss_filter_attr', 'wp_kses' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.semicolon-data-url-css-parser-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng           = self::rng( self::normalize_seed( 'kses-css-semicolon-data-url:' . $seed ) );
		$hook_snapshot = self::snapshot_hook( 'safecss_filter_attr_allow_css' );
		$hook_before   = self::hook_signature( 'safecss_filter_attr_allow_css' );
		$policy        = array(
			'div' => array(
				'data-*' => true,
				'style'  => true,
			),
		);
		$protocols     = array( 'https' );
		$failures      = array();
		$cases         = array();
		$exception     = null;

		$data_url_templates = array(
			array(
				'label' => 'html-base64',
				'mime'  => 'text/html',
				'meta'  => 'base64',
				'body'  => 'PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg',
			),
			array(
				'label' => 'svg-charset',
				'mime'  => 'image/svg+xml',
				'meta'  => 'charset=utf-8',
				'body'  => '%3Csvg%20onload%3Dalert(1)%3E',
			),
			array(
				'label' => 'css-profile',
				'mime'  => 'text/css',
				'meta'  => 'profile=component-fuzz',
				'body'  => 'body%7Bbackground-image%3Aurl(javascript%3Aalert(1))%7D',
			),
			array(
				'label' => 'xml-version',
				'mime'  => 'application/xml',
				'meta'  => 'version=1.0',
				'body'  => '%3Cx%20onclick%3Dalert(1)%2F%3E',
			),
		);
		$properties         = array( 'background-image', 'background', 'cursor', '--cf-data-url' );
		$quotes             = array( '', "'", '"' );

		try {
			for ( $case_index = 0; $case_index < 10; ++$case_index ) {
				$template  = self::rng_choice( $rng, $data_url_templates );
				$property  = self::rng_choice( $rng, $properties );
				$quote     = self::rng_choice( $rng, $quotes );
				$token     = substr( sha1( $seed . ':semicolon-data-url:' . $case_index . ':' . self::rng_uint32( $rng ) ), 0, 8 );
				$data_url  = 'data:' . $template['mime'] . ';' . $template['meta'] . ',' . $template['body'] . $token;
				$safe_url  = 'https://example.test/kses-css/' . $token . '.png';
				$url_value = 'url(' . $quote . $data_url . $quote . ')';
				if ( 'cursor' === $property ) {
					$url_value .= ', auto';
				}

				$css       = $property . ':' . $url_value . ';color:red;background-image:url(' . $safe_url . ')';
				$html      = '<div style="' . htmlspecialchars( $css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" data-token="' . $token . '">x</div>';
				$case_seen = array(
					'permissive' => array(),
					'guarded'    => array(),
				);

				$default_css  = \safecss_filter_attr( $css );
				$default_html = \wp_kses( $html, $policy, $protocols );
				if ( self::contains_data_url( $default_css, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'default-css-preserved-data-url',
						'label'   => $template['label'],
						'css'     => self::preview( $default_css ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( self::contains_data_url( $default_html, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'default-html-preserved-data-url',
						'label'   => $template['label'],
						'html'    => self::preview( $default_html ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( false === strpos( $default_css, 'color:red' ) || false === strpos( $default_css, $safe_url ) ) {
					$failures[] = array(
						'case'       => $case_index,
						'type'       => 'default-css-stripped-safe-neighbor',
						'label'      => $template['label'],
						'css'        => self::preview( $default_css ),
						'expectedUrl' => $safe_url,
					);
				}

				$permissive_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$case_seen ): bool {
					$case_seen['permissive'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return true;
				};
				\add_filter( 'safecss_filter_attr_allow_css', $permissive_filter, 10, 2 );
				try {
					$permissive_css  = \safecss_filter_attr( $css );
					$permissive_html = \wp_kses( $html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $permissive_filter, 10 );
				}

				if ( ! self::seen_semicolon_data_url_split( $case_seen['permissive'], $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'permissive-hook-did-not-see-split-data-url-fragments',
						'label'   => $template['label'],
						'seen'    => $case_seen['permissive'],
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( ! self::contains_data_url( $permissive_css, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'permissive-css-did-not-preserve-current-edge',
						'label'   => $template['label'],
						'css'     => self::preview( $permissive_css ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( ! self::contains_data_url( $permissive_html, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'permissive-html-did-not-preserve-current-edge',
						'label'   => $template['label'],
						'html'    => self::preview( $permissive_html ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}

				$guarded_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$case_seen ): bool {
					$case_seen['guarded'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return ! self::looks_like_semicolon_data_url_css_fragment( $css_test_string );
				};
				\add_filter( 'safecss_filter_attr_allow_css', $guarded_filter, 10, 2 );
				try {
					$guarded_css  = \safecss_filter_attr( $css );
					$guarded_html = \wp_kses( $html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $guarded_filter, 10 );
				}

				if ( self::contains_data_url( $guarded_css, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'guarded-css-preserved-data-url',
						'label'   => $template['label'],
						'css'     => self::preview( $guarded_css ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( self::contains_data_url( $guarded_html, $data_url ) ) {
					$failures[] = array(
						'case'    => $case_index,
						'type'    => 'guarded-html-preserved-data-url',
						'label'   => $template['label'],
						'html'    => self::preview( $guarded_html ),
						'dataUrl' => self::preview( $data_url, 120 ),
					);
				}
				if ( false === strpos( $guarded_css, 'color:red' ) || false === strpos( $guarded_css, $safe_url ) ) {
					$failures[] = array(
						'case'       => $case_index,
						'type'       => 'guarded-css-stripped-safe-neighbor',
						'label'      => $template['label'],
						'css'        => self::preview( $guarded_css ),
						'expectedUrl' => $safe_url,
					);
				}

				$cases[] = array(
					'case'           => $case_index,
					'label'          => $template['label'],
					'property'       => $property,
					'quote'          => $quote,
					'defaultCss'     => self::preview( $default_css, 120 ),
					'permissiveCss'  => self::preview( $permissive_css, 120 ),
					'guardedCss'     => self::preview( $guarded_css, 120 ),
					'permissiveHits' => count( $case_seen['permissive'] ),
					'guardedHits'    => count( $case_seen['guarded'] ),
				);
			}
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			self::restore_hook( 'safecss_filter_attr_allow_css', $hook_snapshot );
		}

		if ( null !== $exception ) {
			return self::throwable_result(
				$seed,
				null,
				'kses.semicolon-data-url-css-parser-matrix.no-throw',
				'data:*;*',
				$exception
			);
		}

		$hook_after       = self::hook_signature( 'safecss_filter_attr_allow_css' );
		$hooks_restored   = $hook_before === $hook_after;
		$hook_was_empty   = empty( $hook_before['exists'] );
		$css_after        = \safecss_filter_attr( 'background-image:url(data:text/html;base64,PHNj);color:red' );
		$css_after_safe   = ! $hook_was_empty || ! self::contains_data_url( $css_after, 'data:text/html;base64,PHNj' );
		if ( ! $hooks_restored || ! $css_after_safe ) {
			$failures[] = array(
				'type'          => 'filter-restoration',
				'hooksRestored' => $hooks_restored,
				'hookWasEmpty'  => $hook_was_empty,
				'cssAfterSafe'  => $css_after_safe,
				'cssAfter'      => self::preview( $css_after ),
				'hookBefore'    => $hook_before,
				'hookAfter'     => $hook_after,
			);
		}

		$details = array(
			'caseCount'    => count( $cases ),
			'policy'       => 'default filters do not preserve complete semicolon-bearing data URLs; all-true allow-CSS exposes current split-fragment edge; guarded allow-CSS rejects split fragments',
			'cases'        => array_slice( $cases, 0, 6 ),
			'failureCount' => count( $failures ),
			'failures'     => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.semicolon-data-url-css-parser-matrix', 'data:*;*', $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.semicolon-data-url-css-parser-matrix',
			'data:*;*',
			'default KSES CSS filtering does not preserve complete semicolon-bearing data URLs, current all-true allow-CSS hook exposure is isolated, guarded allow-CSS rejects split fragments, and hooks restore',
			$failures,
			$details
		);
	}

	private static function check_css_url_protocol_fragment_matrix( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'safecss_filter_attr', 'wp_kses' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.css-url-protocol-fragment-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng           = self::rng( self::normalize_seed( 'kses-css-url-protocol-fragments:' . $seed ) );
		$hook_snapshot = self::snapshot_hook( 'safecss_filter_attr_allow_css' );
		$hook_before   = self::hook_signature( 'safecss_filter_attr_allow_css' );
		$policy        = array(
			'div' => array(
				'data-*' => true,
				'style'  => true,
			),
		);
		$protocols     = array( 'https' );
		$bad_protocols = array( 'javascript', 'vbscript', 'livescript', 'mocha' );
		$properties    = array( 'background-image', 'background', 'cursor', '--cf-fragment-url' );
		$quotes        = array( '', "'", '"' );
		$data_templates = array(
			array(
				'label' => 'bare-comma',
				'url'   => 'data:,hello-',
			),
			array(
				'label' => 'text-plain-comma',
				'url'   => 'data:text/plain,hello-',
			),
			array(
				'label' => 'json-comma',
				'url'   => 'data:application/json,%7B%22token%22%3A%22',
			),
			array(
				'label' => 'png-comma',
				'url'   => 'data:image/png,iVBORw0KGgo',
			),
		);
		$split_prefixes = array(
			'javascript' => 'java',
			'vbscript'   => 'vb',
			'livescript' => 'live',
			'mocha'      => 'moc',
		);
		$function_names = array(
			'javascript' => 'alert',
			'vbscript'   => 'msgbox',
			'livescript' => 'alert',
			'mocha'      => 'evil',
		);
		$failures      = array();
		$cases         = array();
		$exception     = null;

		try {
			for ( $case_index = 0; $case_index < 8; ++$case_index ) {
				$token    = substr( sha1( $seed . ':css-protocol-fragment:' . $case_index . ':' . self::rng_uint32( $rng ) ), 0, 8 );
				$safe_url = 'https://example.test/kses-fragment/' . $token . '.png';

				$data_template = self::rng_choice( $rng, $data_templates );
				$data_property = self::rng_choice( $rng, $properties );
				$data_quote    = self::rng_choice( $rng, $quotes );
				$data_url      = $data_template['url'] . $token;
				$data_value    = 'url(' . $data_quote . $data_url . $data_quote . ')';
				if ( 'cursor' === $data_property ) {
					$data_value .= ', auto';
				}
				$data_css  = $data_property . ':' . $data_value . ';color:red;background-image:url(' . $safe_url . ')';
				$data_html = '<div style="' . htmlspecialchars( $data_css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" data-token="' . $token . '">data</div>';

				$data_seen = array(
					'permissive' => array(),
					'guarded'    => array(),
				);
				$data_default_css  = \safecss_filter_attr( $data_css );
				$data_default_html = \wp_kses( $data_html, $policy, $protocols );

				$data_permissive_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$data_seen ): bool {
					$data_seen['permissive'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return true;
				};
				\add_filter( 'safecss_filter_attr_allow_css', $data_permissive_filter, 10, 2 );
				try {
					$data_permissive_css  = \safecss_filter_attr( $data_css );
					$data_permissive_html = \wp_kses( $data_html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $data_permissive_filter, 10 );
				}

				$data_guarded_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$data_seen ): bool {
					$data_seen['guarded'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return $allow_css && ! self::looks_like_fragmented_bad_protocol_css_fragment( $css_test_string );
				};
				\add_filter( 'safecss_filter_attr_allow_css', $data_guarded_filter, 10, 2 );
				try {
					$data_guarded_css  = \safecss_filter_attr( $data_css );
					$data_guarded_html = \wp_kses( $data_html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $data_guarded_filter, 10 );
				}

				foreach (
					array(
						'default-css'     => $data_default_css,
						'default-html'    => $data_default_html,
						'permissive-css'  => $data_permissive_css,
						'permissive-html' => $data_permissive_html,
						'guarded-css'     => $data_guarded_css,
						'guarded-html'    => $data_guarded_html,
					) as $label => $output
				) {
					if ( self::contains_data_url( $output, $data_url ) || self::contains_protocol_marker( $output, 'data' ) ) {
						$failures[] = array(
							'case'    => $case_index,
							'type'    => 'semicolon-free-data-url-preserved',
							'label'   => $label,
							'dataUrl' => self::preview( $data_url, 120 ),
							'output'  => self::preview( $output ),
						);
					}
				}
				foreach ( array( $data_default_css, $data_permissive_css, $data_guarded_css ) as $label => $output ) {
					if ( false === strpos( $output, 'color:red' ) || false === strpos( $output, $safe_url ) ) {
						$failures[] = array(
							'case'       => $case_index,
							'type'       => 'data-url-safe-neighbor-stripped',
							'label'      => $label,
							'output'     => self::preview( $output ),
							'expectedUrl' => $safe_url,
						);
					}
				}
				foreach ( $data_seen['permissive'] as $hit ) {
					if ( is_array( $hit ) && isset( $hit['test'] ) && self::contains_protocol_marker( (string) $hit['test'], 'data' ) ) {
						$failures[] = array(
							'case' => $case_index,
							'type' => 'data-url-reached-allow-css-filter',
							'hit'  => $hit,
						);
						break;
					}
				}

				$protocol       = self::rng_choice( $rng, $bad_protocols );
				$fragment_shape = self::rng_choice( $rng, array( 'payload-semicolon', 'after-colon-semicolon', 'split-scheme-semicolon' ) );
				$fragment_prop  = self::rng_choice( $rng, $properties );
				$fragment_quote = self::rng_choice( $rng, $quotes );
				$function_name  = $function_names[ $protocol ];
				if ( 'payload-semicolon' === $fragment_shape ) {
					$fragment_url = $protocol . ':' . $function_name . '-' . $token . ';payload';
					$expected_permissive_marker = $protocol . ':';
					$expect_protocol_marker     = true;
					$expect_default_false_hit    = true;
				} elseif ( 'split-scheme-semicolon' === $fragment_shape ) {
					$fragment_url = $split_prefixes[ $protocol ] . ';' . substr( $protocol, strlen( $split_prefixes[ $protocol ] ) ) . ':' . $function_name . '(' . $token . ')';
					$expected_permissive_marker = 'url(' . $fragment_quote . $split_prefixes[ $protocol ];
					$expect_protocol_marker     = false;
					$expect_default_false_hit    = true;
				} else {
					$fragment_url = $protocol . ':;payload-' . $token;
					$expected_permissive_marker = $protocol . ':';
					$expect_protocol_marker     = true;
					$expect_default_false_hit    = true;
				}
				$fragment_value = 'url(' . $fragment_quote . $fragment_url . $fragment_quote . ')';
				if ( 'cursor' === $fragment_prop ) {
					$fragment_value .= ', auto';
				}
				$fragment_css  = $fragment_prop . ':' . $fragment_value . ';color:red;background-image:url(' . $safe_url . '?fragment=1)';
				$fragment_html = '<div style="' . htmlspecialchars( $fragment_css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" data-token="' . $token . '">fragment</div>';
				$fragment_seen = array(
					'permissive' => array(),
					'guarded'    => array(),
				);

				$fragment_default_css  = \safecss_filter_attr( $fragment_css );
				$fragment_default_html = \wp_kses( $fragment_html, $policy, $protocols );
				$fragment_permissive_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$fragment_seen ): bool {
					$fragment_seen['permissive'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return true;
				};
				\add_filter( 'safecss_filter_attr_allow_css', $fragment_permissive_filter, 10, 2 );
				try {
					$fragment_permissive_css  = \safecss_filter_attr( $fragment_css );
					$fragment_permissive_html = \wp_kses( $fragment_html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $fragment_permissive_filter, 10 );
				}

				$fragment_guarded_filter = static function ( bool $allow_css, string $css_test_string ) use ( &$fragment_seen ): bool {
					$fragment_seen['guarded'][] = array(
						'default' => $allow_css,
						'test'    => $css_test_string,
					);
					return $allow_css && ! self::looks_like_fragmented_bad_protocol_css_fragment( $css_test_string );
				};
				\add_filter( 'safecss_filter_attr_allow_css', $fragment_guarded_filter, 10, 2 );
				try {
					$fragment_guarded_css  = \safecss_filter_attr( $fragment_css );
					$fragment_guarded_html = \wp_kses( $fragment_html, $policy, $protocols );
				} finally {
					\remove_filter( 'safecss_filter_attr_allow_css', $fragment_guarded_filter, 10 );
				}

				foreach (
					array(
						'default-css'  => $fragment_default_css,
						'default-html' => $fragment_default_html,
						'guarded-css'  => $fragment_guarded_css,
						'guarded-html' => $fragment_guarded_html,
					) as $label => $output
				) {
					if ( self::contains_protocol_marker( $output, $protocol ) || self::contains_decoded_text( $output, 'url(' . $fragment_quote . $split_prefixes[ $protocol ] ) ) {
						$failures[] = array(
							'case'     => $case_index,
							'type'     => 'bad-protocol-fragment-preserved-outside-permissive-filter',
							'label'    => $label,
							'protocol' => $protocol,
							'shape'    => $fragment_shape,
							'output'   => self::preview( $output ),
						);
					}
				}
				if ( false === self::contains_decoded_text( $fragment_permissive_css, $expected_permissive_marker ) || false === self::contains_decoded_text( $fragment_permissive_html, $expected_permissive_marker ) ) {
					$failures[] = array(
						'case'       => $case_index,
						'type'       => 'permissive-filter-did-not-expose-current-fragment-behavior',
						'protocol'   => $protocol,
						'shape'      => $fragment_shape,
						'expected'   => $expected_permissive_marker,
						'css'        => self::preview( $fragment_permissive_css ),
						'html'       => self::preview( $fragment_permissive_html ),
						'seen'       => $fragment_seen['permissive'],
					);
				}
				if ( $expect_protocol_marker && ( ! self::contains_protocol_marker( $fragment_permissive_css, $protocol ) || ! self::contains_protocol_marker( $fragment_permissive_html, $protocol ) ) ) {
					$failures[] = array(
						'case'     => $case_index,
						'type'     => 'permissive-filter-missing-protocol-marker',
						'protocol' => $protocol,
						'shape'    => $fragment_shape,
						'css'      => self::preview( $fragment_permissive_css ),
						'html'     => self::preview( $fragment_permissive_html ),
					);
				}
				foreach ( array( $fragment_default_css, $fragment_permissive_css, $fragment_guarded_css ) as $label => $output ) {
					if ( false === strpos( $output, 'color:red' ) || false === strpos( $output, $safe_url . '?fragment=1' ) ) {
						$failures[] = array(
							'case'       => $case_index,
							'type'       => 'fragment-safe-neighbor-stripped',
							'label'      => $label,
							'output'     => self::preview( $output ),
							'expectedUrl' => $safe_url . '?fragment=1',
						);
					}
				}
				$saw_default_false_fragment = false;
				foreach ( $fragment_seen['permissive'] as $hit ) {
					if ( is_array( $hit ) && isset( $hit['default'], $hit['test'] ) && false === $hit['default'] && ( false !== strpos( (string) $hit['test'], 'url(' ) || self::contains_protocol_marker( (string) $hit['test'], $protocol ) ) ) {
						$saw_default_false_fragment = true;
						break;
					}
				}
				if ( $expect_default_false_hit && ! $saw_default_false_fragment ) {
					$failures[] = array(
						'case'     => $case_index,
						'type'     => 'permissive-filter-did-not-observe-default-rejected-fragment',
						'protocol' => $protocol,
						'shape'    => $fragment_shape,
						'seen'     => $fragment_seen['permissive'],
					);
				}
				if ( ! $expect_default_false_hit ) {
					foreach ( $fragment_seen['permissive'] as $hit ) {
						if ( is_array( $hit ) && isset( $hit['test'] ) && self::contains_protocol_marker( (string) $hit['test'], $protocol ) ) {
							$failures[] = array(
								'case'     => $case_index,
								'type'     => 'argument-semicolon-protocol-reached-allow-css-filter',
								'protocol' => $protocol,
								'shape'    => $fragment_shape,
								'hit'      => $hit,
							);
							break;
						}
					}
				}

				$cases[] = array(
					'case'               => $case_index,
					'dataLabel'          => $data_template['label'],
					'dataProperty'       => $data_property,
					'dataPermissiveHits' => count( $data_seen['permissive'] ),
					'fragmentProtocol'   => $protocol,
					'fragmentShape'      => $fragment_shape,
					'fragmentProperty'   => $fragment_prop,
					'defaultFragmentCss' => self::preview( $fragment_default_css, 120 ),
					'permissiveFragmentCss' => self::preview( $fragment_permissive_css, 120 ),
					'guardedFragmentCss' => self::preview( $fragment_guarded_css, 120 ),
				);
			}
		} catch ( \Throwable $e ) {
			$exception = $e;
		} finally {
			self::restore_hook( 'safecss_filter_attr_allow_css', $hook_snapshot );
		}

		if ( null !== $exception ) {
			return self::throwable_result(
				$seed,
				null,
				'kses.css-url-protocol-fragment-matrix.no-throw',
				'data:,|bad-protocol;fragment',
				$exception
			);
		}

		$hook_after     = self::hook_signature( 'safecss_filter_attr_allow_css' );
		$hooks_restored = $hook_before === $hook_after;
		$css_after      = \safecss_filter_attr( 'background-image:url(data:,after);background:url(javascript:;after);color:red' );
		if ( ! $hooks_restored || self::contains_protocol_marker( $css_after, 'data' ) || self::contains_protocol_marker( $css_after, 'javascript' ) ) {
			$failures[] = array(
				'type'          => 'filter-restoration',
				'hooksRestored' => $hooks_restored,
				'cssAfter'      => self::preview( $css_after ),
				'hookBefore'    => $hook_before,
				'hookAfter'     => $hook_after,
			);
		}

		$details = array(
			'caseCount'    => count( $cases ),
			'policy'       => 'semicolon-free data URLs are rejected before allow-CSS filters; all-true allow-CSS exposes semicolon-fragmented non-data bad-protocol fragments; guarded filters retain safe neighbors while rejecting those fragments',
			'cases'        => array_slice( $cases, 0, 6 ),
			'failureCount' => count( $failures ),
			'failures'     => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.css-url-protocol-fragment-matrix', 'data:,|bad-protocol;fragment', $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.css-url-protocol-fragment-matrix',
			'data:,|bad-protocol;fragment',
			'semicolon-free data URLs are rejected before allow-CSS filters, semicolon-fragmented bad-protocol behavior is isolated, guarded filters reject fragments, safe neighbors survive, and hooks restore',
			$failures,
			$details
		);
	}

	private static function check_pdf_object_and_uri_attribute_invariants( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'wp_kses_post', 'wp_kses_hair', '_wp_kses_allow_pdf_objects' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						null,
						'kses.pdf-object-uri-helpers.available',
						$function_name . '() is not loaded',
						''
					),
				);
			}
		}

		$results = array();
		try {
			$results[] = self::check_pdf_object_policy_matrix( $seed );
			$results[] = self::check_uri_attribute_filter_scope( $seed );
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, null, 'kses.pdf-object-uri-invariants-no-throw', '', $e );
		}

		return $results;
	}

	private static function check_helper_contract_matrix( int $seed ): array {
		$required = array(
			'wp_kses_array_lc',
			'wp_kses_decode_entities',
			'wp_kses_html_error',
			'wp_kses_named_entities',
			'wp_kses_normalize_entities',
			'wp_kses_normalize_entities2',
			'wp_kses_normalize_entities3',
			'wp_kses_stripslashes',
			'wp_kses_xml_named_entities',
		);
		foreach ( $required as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.helper-contract-matrix.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$failures = array();
		try {
			$array_input    = array(
				'DIV'  => array(
					'DATA-CF' => true,
					'Title'   => false,
				),
				'SPAN' => array(
					'ARIA-LABEL' => 'ok',
					'CLASS'      => true,
				),
			);
			$array_expected = array(
				'div'  => array(
					'data-cf' => true,
					'title'   => false,
				),
				'span' => array(
					'aria-label' => 'ok',
					'class'      => true,
				),
			);
			$array_actual   = \wp_kses_array_lc( $array_input );
			if ( $array_expected !== $array_actual ) {
				$failures[] = array(
					'label'    => 'wp_kses_array_lc lowercases tag and attribute keys without changing values',
					'expected' => $array_expected,
					'actual'   => $array_actual,
				);
			}

			$slash_input    = 'alpha \"quoted\" beta \\\'single\\\' gamma \\path\\tail';
			$slash_expected = 'alpha "quoted" beta \\\'single\\\' gamma \\path\\tail';
			$slash_actual   = \wp_kses_stripslashes( $slash_input );
			if ( $slash_expected !== $slash_actual ) {
				$failures[] = array(
					'label'    => 'wp_kses_stripslashes only strips slashes before double quotes',
					'expected' => $slash_expected,
					'actual'   => $slash_actual,
				);
			}

			$error_input    = '"bad value" next=safe tail';
			$error_expected = 'next=safe tail';
			$error_actual   = \wp_kses_html_error( $error_input );
			if ( $error_expected !== $error_actual ) {
				$failures[] = array(
					'label'    => 'wp_kses_html_error consumes malformed quoted attribute prefix through following whitespace',
					'expected' => $error_expected,
					'actual'   => $error_actual,
				);
			}

			$decode_input    = '&#065; &#x42; &amp;copy;';
			$decode_expected = 'A B &amp;copy;';
			$decode_actual   = \wp_kses_decode_entities( $decode_input );
			if ( $decode_expected !== $decode_actual ) {
				$failures[] = array(
					'label'    => 'wp_kses_decode_entities decodes decimal and hex numeric references only',
					'expected' => $decode_expected,
					'actual'   => $decode_actual,
				);
			}

			$entity_input   = '&copy; &amp; &lt; &bogus; &#065; &#x42; &#x110000;';
			$html_expected  = '&copy; &amp; &lt; &amp;bogus; &#065; &#x42; &amp;#x110000;';
			$xml_expected   = html_entity_decode( '&copy;', ENT_HTML5, 'UTF-8' ) . ' &amp; &lt; &amp;bogus; &#065; &#x42; &amp;#x110000;';
			$html_actual    = \wp_kses_normalize_entities( $entity_input );
			$xml_actual     = \wp_kses_normalize_entities( $entity_input, 'xml' );
			$html_named     = \wp_kses_named_entities( array( '&amp;copy;', 'copy' ) );
			$xml_named      = \wp_kses_xml_named_entities( array( '&amp;copy;', 'copy' ) );
			$decimal_valid  = \wp_kses_normalize_entities2( array( '&amp;#065;', '065' ) );
			$decimal_bad    = \wp_kses_normalize_entities2( array( '&amp;#0000000;', '0000000' ) );
			$hex_valid      = \wp_kses_normalize_entities3( array( '&amp;#x042;', '042' ) );
			$hex_bad        = \wp_kses_normalize_entities3( array( '&amp;#x110000;', '110000' ) );
			if (
				$html_expected !== $html_actual
				|| $xml_expected !== $xml_actual
				|| '&copy;' !== $html_named
				|| html_entity_decode( '&copy;', ENT_HTML5, 'UTF-8' ) !== $xml_named
				|| '&#065;' !== $decimal_valid
				|| '&amp;#0000000;' !== $decimal_bad
				|| '&#x42;' !== $hex_valid
				|| '&amp;#x110000;' !== $hex_bad
			) {
				$failures[] = array(
					'label' => 'entity helper callbacks preserve HTML/XML named-entity and valid-Unicode contracts',
					'expected' => array(
						'html'         => $html_expected,
						'xml'          => $xml_expected,
						'htmlNamed'    => '&copy;',
						'xmlNamed'     => html_entity_decode( '&copy;', ENT_HTML5, 'UTF-8' ),
						'decimalValid' => '&#065;',
						'decimalBad'   => '&amp;#0000000;',
						'hexValid'     => '&#x42;',
						'hexBad'       => '&amp;#x110000;',
					),
					'actual' => array(
						'html'         => $html_actual,
						'xml'          => $xml_actual,
						'htmlNamed'    => $html_named,
						'xmlNamed'     => $xml_named,
						'decimalValid' => $decimal_valid,
						'decimalBad'   => $decimal_bad,
						'hexValid'     => $hex_valid,
						'hexBad'       => $hex_bad,
					),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_result( $seed, null, 'kses.helper-contract-matrix.no-throw', '', $e );
		}

		$details = array(
			'helpers'      => $required,
			'failureCount' => count( $failures ),
			'failures'     => array_slice( $failures, 0, 6 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.helper-contract-matrix', implode( ',', $required ), $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.helper-contract-matrix',
			implode( ',', $required ),
			'low-level KSES helper contracts match exact bounded expectations',
			$failures,
			$details
		);
	}

	private static function check_no_null_control_matrix( int $seed ): array {
		foreach ( array( 'wp_kses_bad_protocol', 'wp_kses_no_null' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'wp_kses_no_null.control-character-contracts.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$failures      = array();
		$removed_bytes = array_merge( range( 0, 8 ), array( 11, 12 ), range( 14, 31 ) );
		$kept_bytes    = array( 9, 10, 13 );

		try {
			foreach ( $removed_bytes as $byte ) {
				$input   = 'left' . chr( $byte ) . 'right';
				$default = \wp_kses_no_null( $input );
				$keep    = \wp_kses_no_null( $input, array( 'slash_zero' => 'keep' ) );
				if ( 'leftright' !== $default || 'leftright' !== $keep ) {
					$failures[] = array(
						'label'   => 'raw disallowed C0 controls are removed in both slash-zero modes',
						'byte'    => $byte,
						'default' => self::preview( $default ),
						'keep'    => self::preview( $keep ),
					);
				}
			}

			foreach ( $kept_bytes as $byte ) {
				$input   = 'left' . chr( $byte ) . 'right';
				$default = \wp_kses_no_null( $input );
				$keep    = \wp_kses_no_null( $input, array( 'slash_zero' => 'keep' ) );
				if ( $input !== $default || $input !== $keep ) {
					$failures[] = array(
						'label'   => 'tab, line feed, and carriage return are preserved',
						'byte'    => $byte,
						'input'   => self::preview( $input ),
						'default' => self::preview( $default ),
						'keep'    => self::preview( $keep ),
					);
				}
			}

			$slash_cases = array(
				'singleSlashZero'      => array(
					'input'   => 'path\\0tail',
					'default' => 'pathtail',
					'keep'    => 'path\\0tail',
				),
				'multiSlashZeros'      => array(
					'input'   => 'path\\\\000tail',
					'default' => 'pathtail',
					'keep'    => 'path\\\\000tail',
				),
				'internalSlashZeroRuns' => array(
					'input'   => 'a\\00b\\\\0c',
					'default' => 'abc',
					'keep'    => 'a\\00b\\\\0c',
				),
			);
			foreach ( $slash_cases as $label => $case ) {
				$default = \wp_kses_no_null( $case['input'] );
				$keep    = \wp_kses_no_null( $case['input'], array( 'slash_zero' => 'keep' ) );
				if ( $case['default'] !== $default || $case['keep'] !== $keep ) {
					$failures[] = array(
						'label'           => 'literal slash-zero runs follow the slash_zero option',
						'case'            => $label,
						'expectedDefault' => self::preview( $case['default'] ),
						'actualDefault'   => self::preview( $default ),
						'expectedKeep'    => self::preview( $case['keep'] ),
						'actualKeep'      => self::preview( $keep ),
					);
				}
			}

			$protocol_cases = array(
				'slashZeroJavascript' => array(
					'input'     => 'java\\0script:alert(1)',
					'protocols' => array( 'http', 'https' ),
					'expected'  => 'alert(1)',
				),
				'rawNullJavascript'   => array(
					'input'     => 'java' . chr( 0 ) . 'script:alert(1)',
					'protocols' => array( 'http', 'https' ),
					'expected'  => 'alert(1)',
				),
				'slashZeroHttps'      => array(
					'input'     => 'http\\0s://example.test/path',
					'protocols' => array( 'https' ),
					'expected'  => 'https://example.test/path',
				),
				'rawControlHttps'     => array(
					'input'     => 'h' . chr( 8 ) . 'ttps://example.test/path',
					'protocols' => array( 'https' ),
					'expected'  => 'https://example.test/path',
				),
			);
			foreach ( $protocol_cases as $label => $case ) {
				$actual = \wp_kses_bad_protocol( $case['input'], $case['protocols'] );
				if ( $case['expected'] !== $actual ) {
					$failures[] = array(
						'label'     => 'bad-protocol filtering consumes null-obfuscated schemes before protocol decisions',
						'case'      => $label,
						'protocols' => $case['protocols'],
						'input'     => self::preview( $case['input'] ),
						'expected'  => self::preview( $case['expected'] ),
						'actual'    => self::preview( $actual ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_result( $seed, null, 'wp_kses_no_null.control-character-contracts-no-throw', '', $e );
		}

		$details = array(
			'removedByteCount' => count( $removed_bytes ),
			'keptBytes'        => $kept_bytes,
			'slashCases'       => array_keys( $slash_cases ),
			'protocolCases'    => array_keys( $protocol_cases ),
			'failureCount'     => count( $failures ),
			'failures'         => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'wp_kses_no_null.control-character-contracts', '', $details );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_no_null.control-character-contracts',
			'',
			'raw C0 controls, slash-zero runs, and null-obfuscated protocols follow KSES contracts',
			$failures,
			$details
		);
	}

	private static function check_kses_filter_lifecycle_invariants( int $seed ): array {
		$required = array(
			'add_filter',
			'current_user_can',
			'has_filter',
			'kses_init',
			'kses_init_filters',
			'kses_remove_filters',
			'remove_filter',
			'wp_filter_global_styles_post',
			'wp_filter_kses',
			'wp_filter_post_kses',
		);
		foreach ( $required as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.filter-lifecycle.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$hooks     = self::kses_filter_lifecycle_hooks();
		$snapshot = self::snapshot_hooks( $hooks );
		$failures = array();

		$sentinels = array();
		foreach ( $hooks as $hook ) {
			$sentinels[ $hook ] = static function ( $value ) {
				return $value;
			};
		}

		$with_unfiltered_html = static function ( bool $grant, callable $callback ) {
			$cap_filter = static function ( array $allcaps ) use ( $grant ): array {
				$allcaps['unfiltered_html'] = $grant;
				return $allcaps;
			};

			\add_filter( 'user_has_cap', $cap_filter, 1000, 4 );
			try {
				return $callback();
			} finally {
				\remove_filter( 'user_has_cap', $cap_filter, 1000 );
			}
		};

		try {
			\kses_remove_filters();
			foreach ( $sentinels as $hook => $callback ) {
				\add_filter( $hook, $callback, 123, 1 );
			}

			$denied = $with_unfiltered_html(
				false,
				static function (): array {
					\kses_remove_filters();
					\kses_init_filters();
					return self::kses_filter_lifecycle_state();
				}
			);
			$failures = array_merge(
				$failures,
				self::kses_filter_lifecycle_failures(
					'kses_init_filters denied unfiltered_html',
					$denied,
					self::kses_filter_lifecycle_expected( 'denied' )
				)
			);

			\kses_remove_filters();
			$removed = self::kses_filter_lifecycle_state();
			$failures = array_merge(
				$failures,
				self::kses_filter_lifecycle_failures(
					'kses_remove_filters removes KSES callbacks',
					$removed,
					self::kses_filter_lifecycle_expected( 'removed' )
				)
			);
			foreach ( $sentinels as $hook => $callback ) {
				if ( 123 !== \has_filter( $hook, $callback ) ) {
					$failures[] = array(
						'label' => 'kses_remove_filters preserves unrelated callbacks',
						'hook'  => $hook,
						'state' => self::compact_value( self::hook_signature( $hook ) ),
					);
				}
			}

			$granted = $with_unfiltered_html(
				true,
				static function (): array {
					\kses_remove_filters();
					\kses_init_filters();
					return self::kses_filter_lifecycle_state();
				}
			);
			$failures = array_merge(
				$failures,
				self::kses_filter_lifecycle_failures(
					'kses_init_filters granted unfiltered_html',
					$granted,
					self::kses_filter_lifecycle_expected( 'granted' )
				)
			);

			$init_denied = $with_unfiltered_html(
				false,
				static function (): array {
					\kses_remove_filters();
					\kses_init();
					return self::kses_filter_lifecycle_state();
				}
			);
			$failures = array_merge(
				$failures,
				self::kses_filter_lifecycle_failures(
					'kses_init denied unfiltered_html',
					$init_denied,
					self::kses_filter_lifecycle_expected( 'denied' )
				)
			);

			$init_granted = $with_unfiltered_html(
				true,
				static function (): array {
					\kses_remove_filters();
					\kses_init_filters();
					\kses_init();
					return self::kses_filter_lifecycle_state();
				}
			);
			$failures = array_merge(
				$failures,
				self::kses_filter_lifecycle_failures(
					'kses_init granted unfiltered_html',
					$init_granted,
					self::kses_filter_lifecycle_expected( 'removed' )
				)
			);
			foreach ( $sentinels as $hook => $callback ) {
				if ( 123 !== \has_filter( $hook, $callback ) ) {
					$failures[] = array(
						'label' => 'kses_init preserves unrelated callbacks while removing KSES filters for unfiltered users',
						'hook'  => $hook,
						'state' => self::compact_value( self::hook_signature( $hook ) ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			return self::throwable_result( $seed, null, 'kses.filter-lifecycle.no-throw', '', $e );
		} finally {
			self::restore_hooks( $snapshot );
		}

		$details = array(
			'hooks'        => $hooks,
			'failureCount' => count( $failures ),
			'failures'     => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.filter-lifecycle.hook-registration-capability-gates', implode( ',', $hooks ), $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.filter-lifecycle.hook-registration-capability-gates',
			implode( ',', $hooks ),
			'KSES save/comment filters are registered, removed, and capability-gated with unrelated callbacks preserved',
			$failures,
			$details
		);
	}

	private static function check_wp_kses_post_deep_invariants( int $seed ): array {
		foreach ( array( 'map_deep', 'wp_kses_post', 'wp_kses_post_deep' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'wp_kses_post_deep.nested-structure-equivalence.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$rng                    = self::rng( self::normalize_seed( 'wp-kses-post-deep:' . $seed ) );
		$unsafe_key             = '<script>array-key</script>';
		$cases                  = self::wp_kses_post_deep_cases( $rng, $unsafe_key );
		$input                  = 'wp_kses_post_deep cases: ' . implode( ',', array_keys( $cases ) );
		$global_snapshot        = self::snapshot_globals( self::kses_global_names() );
		$pre_kses_hook_snapshot = self::snapshot_hook( 'pre_kses' );
		$pre_kses_signature     = self::hook_signature( 'pre_kses' );
		$failures               = array();
		$leaf_count             = 0;
		$string_leaf_count      = 0;
		$unsafe_key_cases       = 0;
		$unsafe_token_count     = 0;
		$safe_marker_seen       = false;

		try {
			foreach ( $cases as $label => $payload ) {
				$leaf_count        += self::deep_leaf_count( $payload );
				$string_leaf_count += self::deep_string_leaf_count( $payload );
				$input_shape        = self::deep_container_signature( $payload );
				$expected           = \map_deep( self::deep_clone_value( $payload ), 'wp_kses_post' );
				$actual             = \wp_kses_post_deep( self::deep_clone_value( $payload ) );

				if ( ! self::deep_values_match( $expected, $actual ) ) {
					$failures[] = array(
						'label'        => 'wp_kses_post_deep is equivalent to map_deep(..., wp_kses_post)',
						'case'         => $label,
						'expectedSha1' => self::deep_value_sha1( $expected ),
						'actualSha1'   => self::deep_value_sha1( $actual ),
					);
				}

				$actual_shape = self::deep_container_signature( $actual );
				if ( $input_shape !== $actual_shape ) {
					$failures[] = array(
						'label'    => 'arrays, public objects, keys, and property names keep their shape',
						'case'     => $label,
						'expected' => $input_shape,
						'actual'   => $actual_shape,
					);
				}

				$leaf_failures = self::wp_kses_post_deep_leaf_failures( $payload, $actual );
				foreach ( array_slice( $leaf_failures, 0, 4 ) as $leaf_failure ) {
					$leaf_failure['case'] = $label;
					$failures[]           = $leaf_failure;
				}

				$idempotent = \wp_kses_post_deep( self::deep_clone_value( $actual ) );
				if ( ! self::deep_values_match( $actual, $idempotent ) ) {
					$failures[] = array(
						'label'      => 'sanitized nested post content is idempotent',
						'case'       => $label,
						'actualSha1' => self::deep_value_sha1( $actual ),
						'againSha1'  => self::deep_value_sha1( $idempotent ),
					);
				}

				$unsafe_tokens       = self::wp_kses_post_deep_unsafe_leaf_tokens( $actual );
				$unsafe_token_count += count( $unsafe_tokens );
				foreach ( array_slice( $unsafe_tokens, 0, 4 ) as $unsafe_token ) {
					$unsafe_token['case'] = $label;
					$failures[]           = $unsafe_token;
				}

				if ( self::deep_string_leaf_contains( $actual, 'https://example.test/' ) ) {
					$safe_marker_seen = true;
				}

				if ( self::deep_contains_array_key( $payload, $unsafe_key ) ) {
					++$unsafe_key_cases;
					if ( ! self::deep_contains_array_key( $actual, $unsafe_key ) ) {
						$failures[] = array(
							'label' => 'map_deep leaves array keys untouched even when values are sanitized',
							'case'  => $label,
							'key'   => $unsafe_key,
						);
					}
				}
			}

			if ( ! $safe_marker_seen ) {
				$failures[] = array(
					'label' => 'safe post-content markers survive nested deep filtering',
				);
			}

			$pre_kses_after = self::hook_signature( 'pre_kses' );
			if ( $pre_kses_signature !== $pre_kses_after ) {
				$failures[] = array(
					'label'    => 'wp_kses_post_deep leaves the pre_kses hook registration stable',
					'expected' => $pre_kses_signature,
					'actual'   => $pre_kses_after,
				);
			}

			if ( ! self::globals_match( array( 'wp_current_filter' => $global_snapshot['wp_current_filter'] ) ) ) {
				$failures[] = array(
					'label'    => 'wp_kses_post_deep leaves the current filter stack stable',
					'expected' => self::describe_global_snapshot( array( 'wp_current_filter' => $global_snapshot['wp_current_filter'] ) ),
					'actual'   => self::describe_global_snapshot( self::snapshot_globals( array( 'wp_current_filter' ) ) ),
				);
			}
		} catch ( \Throwable $e ) {
			return self::throwable_result( $seed, null, 'wp_kses_post_deep.nested-structure-equivalence-no-throw', $input, $e );
		} finally {
			self::restore_hook( 'pre_kses', $pre_kses_hook_snapshot );
			self::restore_globals( $global_snapshot );
		}

		$details = array(
			'caseCount'        => count( $cases ),
			'leafCount'        => $leaf_count,
			'stringLeafCount'  => $string_leaf_count,
			'unsafeKeyCases'   => $unsafe_key_cases,
			'unsafeTokenCount' => $unsafe_token_count,
			'failureCount'     => count( $failures ),
			'failures'         => array_slice( $failures, 0, 8 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'wp_kses_post_deep.nested-structure-equivalence', $input, $details );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_post_deep.nested-structure-equivalence',
			$input,
			'nested arrays and public objects map exactly through wp_kses_post(), preserving structure and sanitized post content',
			$failures,
			$details
		);
	}

	private static function wp_kses_post_deep_cases( array &$rng, string $unsafe_key ): array {
		$token        = substr( sha1( 'post-deep:' . self::rng_uint32( $rng ) ), 0, 10 );
		$primary_url  = 'https://example.test/kses/' . $token;
		$nested_object = self::std_object(
			array(
				'body'    => '<p onclick="evil()">Safe <strong>strong</strong><script>alert(1)</script></p>',
				'link'    => '<a href="javascript:alert(1)" onclick="evil()">bad</a><a href="' . $primary_url . '">good</a>',
				'counter' => self::rng_int( $rng, 2, 97 ),
			)
		);
		$nested_object->child = self::std_object(
			array(
				'style' => '<span style="color:red;background-image:url(javascript:alert(1))" data-token="' . $token . '">styled</span>',
				'flag'  => true,
			)
		);

		$cases = array(
			'top-level-string'       => '<p>Safe <strong>bold</strong></p><script>alert(1)</script>',
			'top-level-int'          => self::rng_int( $rng, 100, 999 ),
			'array-key-contract'     => array(
				$unsafe_key => '<img src="' . $primary_url . '.png" onerror="evil()" alt="bad">',
				'safe'      => '<a href="' . $primary_url . '?ok=1">safe link</a>',
				'nested'    => array(
					$unsafe_key . ':nested' => '<a href="vbscript:msgbox(1)" onclick="evil()">nested</a>',
					'scalars'               => array(
						'true'  => true,
						'false' => false,
						'int'   => self::rng_int( $rng, 1, 500 ),
						'float' => self::rng_int( $rng, 10, 99 ) / 10,
					),
					'object'                => $nested_object,
				),
			),
			'public-object-contract' => self::std_object(
				array(
					'title'    => '<h2>Title ' . $token . '</h2>',
					'embed'    => '<iframe src="' . $primary_url . '"></iframe><blockquote cite="' . $primary_url . '">quote</blockquote>',
					'children' => array(
						self::wp_kses_post_deep_fragment( $rng ),
						self::std_object(
							array(
								'html' => self::wp_kses_post_deep_fragment( $rng ),
								'bool' => false,
							)
						),
					),
				)
			),
		);

		for ( $i = 0; $i < 6; ++$i ) {
			$case_token = substr( sha1( 'post-deep-generated:' . $i . ':' . self::rng_uint32( $rng ) ), 0, 8 );
			$cases[ 'generated-' . $i ] = array(
				'html'                  => self::wp_kses_post_deep_fragment( $rng ),
				$unsafe_key . '-' . $i => self::wp_kses_post_deep_fragment( $rng ),
				'scalars'               => array(
					'int'   => self::rng_int( $rng, -200, 200 ),
					'float' => self::rng_int( $rng, 5, 90 ) / 7,
					'bool'  => self::rng_chance( $rng, 50 ),
				),
				'object'                => self::std_object(
					array(
						'caption' => '<figcaption data-token="' . $case_token . '">Caption <em>ok</em><script>alert(1)</script></figcaption>',
						'url'     => '<a href="https://example.test/generated/' . $case_token . '">generated</a>',
						'bad'     => '<a href="data:text/html,<script>alert(1)</script>" onclick="evil()">data</a>',
					)
				),
			);
		}

		return $cases;
	}

	private static function wp_kses_post_deep_fragment( array &$rng ): string {
		$token = substr( sha1( 'post-deep-fragment:' . self::rng_uint32( $rng ) ), 0, 8 );
		$url   = 'https://example.test/fragment/' . $token;

		return self::rng_choice(
			$rng,
			array(
				'<p>Text ' . $token . ' <strong>strong</strong> <em>em</em></p>',
				'<script>alert("' . $token . '")</script><a href="' . $url . '">safe</a>',
				'<a href="javascript:alert(1)" onclick="evil()">bad</a><a href="' . $url . '?ok=1">good</a>',
				'<img src="' . $url . '.png" onerror="evil()" alt="image ' . $token . '">',
				'<span style="color: red; background-image: url(javascript:alert(1));" data-token="' . $token . '">styled</span>',
				'<iframe src="' . $url . '"></iframe><blockquote cite="' . $url . '">quote</blockquote>',
				'<p data-token="' . $token . '"><code>&lt;safe&gt;</code><svg onload="evil()"></svg></p>',
			)
		);
	}

	private static function wp_kses_post_deep_leaf_failures( $input, $actual, string $path = '$' ): array {
		$failures = array();
		if ( is_array( $input ) ) {
			if ( ! is_array( $actual ) ) {
				return array(
					array(
						'label' => 'container changed while checking leaf equivalence',
						'path'  => $path,
						'type'  => gettype( $actual ),
					),
				);
			}

			foreach ( $input as $key => $value ) {
				$failures = array_merge(
					$failures,
					self::wp_kses_post_deep_leaf_failures( $value, $actual[ $key ] ?? null, $path . self::deep_path_segment( $key ) )
				);
			}
			return $failures;
		}

		if ( is_object( $input ) ) {
			if ( ! is_object( $actual ) ) {
				return array(
					array(
						'label' => 'object changed while checking leaf equivalence',
						'path'  => $path,
						'type'  => gettype( $actual ),
					),
				);
			}

			foreach ( get_object_vars( $input ) as $property => $value ) {
				$actual_vars = get_object_vars( $actual );
				$failures    = array_merge(
					$failures,
					self::wp_kses_post_deep_leaf_failures( $value, $actual_vars[ $property ] ?? null, $path . '->' . $property )
				);
			}
			return $failures;
		}

		$expected = \wp_kses_post( $input );
		if ( $expected !== $actual ) {
			$failures[] = array(
				'label'    => 'each scalar leaf maps through wp_kses_post',
				'path'     => $path,
				'input'    => self::preview( (string) $input ),
				'expected' => self::preview( (string) $expected ),
				'actual'   => is_scalar( $actual ) ? self::preview( (string) $actual ) : gettype( $actual ),
			);
		}

		return $failures;
	}

	private static function wp_kses_post_deep_unsafe_leaf_tokens( $value, string $path = '$' ): array {
		$failures = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$failures = array_merge( $failures, self::wp_kses_post_deep_unsafe_leaf_tokens( $item, $path . self::deep_path_segment( $key ) ) );
			}
			return $failures;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property => $item ) {
				$failures = array_merge( $failures, self::wp_kses_post_deep_unsafe_leaf_tokens( $item, $path . '->' . $property ) );
			}
			return $failures;
		}

		if ( ! is_string( $value ) ) {
			return $failures;
		}

		foreach ( array( '<script', 'javascript:', 'vbscript:', 'data:text/html', '<iframe', '<svg' ) as $token ) {
			if ( false !== stripos( $value, $token ) ) {
				$failures[] = array(
					'label'   => 'sanitized leaf still contains an unsafe post-content token',
					'path'    => $path,
					'token'   => $token,
					'preview' => self::preview( $value ),
				);
			}
		}

		if ( preg_match( '/<[^>]*\son[a-z0-9_-]+\s*=/i', $value, $match ) ) {
			$failures[] = array(
				'label'   => 'sanitized leaf still contains an event-handler attribute',
				'path'    => $path,
				'token'   => $match[0],
				'preview' => self::preview( $value ),
			);
		}

		return $failures;
	}

	private static function std_object( array $properties ): \stdClass {
		$object = new \stdClass();
		foreach ( $properties as $name => $value ) {
			$object->$name = $value;
		}
		return $object;
	}

	private static function deep_clone_value( $value ) {
		return unserialize( serialize( $value ) );
	}

	private static function deep_values_match( $expected, $actual ): bool {
		return serialize( $expected ) === serialize( $actual );
	}

	private static function deep_value_sha1( $value ): string {
		return sha1( serialize( $value ) );
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
			foreach ( get_object_vars( $value ) as $item ) {
				$count += self::deep_leaf_count( $item );
			}
			return $count;
		}

		return 1;
	}

	private static function deep_string_leaf_count( $value ): int {
		if ( is_array( $value ) ) {
			$count = 0;
			foreach ( $value as $item ) {
				$count += self::deep_string_leaf_count( $item );
			}
			return $count;
		}

		if ( is_object( $value ) ) {
			$count = 0;
			foreach ( get_object_vars( $value ) as $item ) {
				$count += self::deep_string_leaf_count( $item );
			}
			return $count;
		}

		return is_string( $value ) ? 1 : 0;
	}

	private static function deep_container_signature( $value ): array {
		if ( is_array( $value ) ) {
			$children = array();
			foreach ( $value as $key => $item ) {
				$children[] = array(
					'keyType' => gettype( $key ),
					'key'     => (string) $key,
					'shape'   => self::deep_container_signature( $item ),
				);
			}
			return array(
				'type'     => 'array',
				'children' => $children,
			);
		}

		if ( is_object( $value ) ) {
			$properties = array();
			foreach ( get_object_vars( $value ) as $property => $item ) {
				$properties[] = array(
					'name'  => $property,
					'shape' => self::deep_container_signature( $item ),
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

	private static function deep_contains_array_key( $value, string $needle ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( $needle === $key || self::deep_contains_array_key( $item, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( self::deep_contains_array_key( $item, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function deep_string_leaf_contains( $value, string $needle ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::deep_string_leaf_contains( $item, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( self::deep_string_leaf_contains( $item, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		return is_string( $value ) && false !== strpos( $value, $needle );
	}

	private static function deep_path_segment( $key ): string {
		return '[' . gettype( $key ) . ':' . self::preview( (string) $key, 80 ) . ']';
	}

	private static function check_block_attribute_kses_invariants( int $seed ): array {
		$required = array(
			'add_filter',
			'filter_block_content',
			'filter_block_kses',
			'filter_block_kses_value',
			'has_filter',
			'parse_blocks',
			'remove_filter',
			'serialize_block',
			'serialize_blocks',
			'wp_kses',
			'wp_pre_kses_block_attributes',
		);
		foreach ( $required as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return self::skip(
					$seed,
					null,
					'kses.block-attribute-filtering.available',
					$function_name . '() is not loaded',
					''
				);
			}
		}

		$token        = substr( sha1( 'block-attributes:' . $seed ), 0, 10 );
		$allowed     = self::block_attribute_allowed_html();
		$protocols   = array( 'https', 'mailto' );
		$block       = self::block_attribute_case( $token );
		$value_probe = array(
			'plain'                   => 'Text ' . $token,
			'html'                    => '<a href="javascript:alert(1)" onclick="evil()">Bad</a><a href="https://example.test/' . $token . '">Good</a>',
			'<script>bad</script>Key' => '<span style="color:red;background-image:url(javascript:alert(1))" data-safe="yes" onclick="evil()">Span</span>',
			'nested'                  => array(
				'number'  => 42,
				'flag'    => true,
				'nothing' => null,
				'mailto'  => '<a href="mailto:test@example.test">Mail</a>',
			),
		);
		$template_probe = array(
			'tagName' => 'script',
			'nested'  => array(
				'tagName' => 'main',
			),
			'label'   => 'Header ' . $token,
		);
		$failures = array();

		try {
			$hook_priority_before = \has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
			$hook_snapshot_before = self::snapshot_hook( 'pre_kses' );
			$hook_signature_before = self::hook_signature( 'pre_kses' );
			$hook_added            = false;
			try {
				if ( false === $hook_priority_before ) {
					\add_filter( 'pre_kses', 'wp_pre_kses_block_attributes', 10, 3 );
					$hook_added = true;
				}
				$serialized          = \serialize_blocks( array( $block ) );
				$parsed              = \parse_blocks( $serialized );
				$direct_block        = \filter_block_kses( $parsed[0], $allowed, $protocols );
				$direct_serialized   = \serialize_block( $direct_block );
				$content_filtered    = \filter_block_content( $serialized, $allowed, $protocols );
				$content_refiltered  = \filter_block_content( $content_filtered, $allowed, $protocols );
				$content_reparsed    = \parse_blocks( $content_filtered );
				$value_actual        = \filter_block_kses_value( $value_probe, $allowed, $protocols );
				$value_expected      = self::block_kses_value_reference( $value_probe, $allowed, $protocols );
				$template_actual     = \filter_block_kses_value(
					$template_probe,
					$allowed,
					$protocols,
					array( 'blockName' => 'core/template-part' )
				);
				$template_expected   = self::block_kses_value_reference(
					$template_probe,
					$allowed,
					$protocols,
					array( 'blockName' => 'core/template-part' )
				);
				$hooked = \wp_kses( $serialized, $allowed, $protocols );
				$hook_expected = \wp_kses( $content_filtered, $allowed, $protocols );
			} finally {
				$hook_priority_after  = \has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
				$hook_signature_after = self::hook_signature( 'pre_kses' );
				self::restore_hook( 'pre_kses', $hook_snapshot_before );
				$hook_signature_restored = self::hook_signature( 'pre_kses' );
			}
		} catch ( \Throwable $e ) {
			if ( isset( $hook_snapshot_before ) ) {
				self::restore_hook( 'pre_kses', $hook_snapshot_before );
			}
			return self::throwable_result( $seed, null, 'kses.block-attribute-filtering.no-throw', '', $e );
		}

		if ( $direct_serialized !== $content_filtered ) {
			$failures[] = array(
				'label'  => 'filter_block_content agrees with filter_block_kses plus serialize_block',
				'direct' => self::preview( $direct_serialized ),
				'filter' => self::preview( $content_filtered ),
			);
		}
		if ( $content_filtered !== $content_refiltered ) {
			$failures[] = array(
				'label'     => 'filter_block_content is idempotent',
				'filtered'  => self::preview( $content_filtered ),
				'refiltered' => self::preview( $content_refiltered ),
			);
		}
		if ( ! isset( $content_reparsed[0]['attrs'] ) || $direct_block['attrs'] !== $content_reparsed[0]['attrs'] ) {
			$failures[] = array(
				'label'  => 'serialized filtered block reparses to direct filtered attrs',
				'direct' => $direct_block['attrs'] ?? null,
				'parsed' => $content_reparsed[0]['attrs'] ?? null,
			);
		}
		if ( $value_expected !== $value_actual ) {
			$failures[] = array(
				'label'    => 'filter_block_kses_value recursively filters string keys and leaves while preserving scalar types',
				'expected' => $value_expected,
				'actual'   => $value_actual,
			);
		}
		$link_html   = (string) ( $direct_block['attrs']['linkHtml'] ?? '' );
		$nested_html = (string) ( $direct_block['attrs']['nested']['badKey'] ?? '' );
		$value_html  = (string) ( $value_actual['html'] ?? '' );
		if (
			false !== stripos( $link_html, 'javascript:' )
			|| false !== stripos( $link_html, 'onclick' )
			|| false === strpos( $link_html, 'https://example.test/' . $token )
			|| false !== stripos( $nested_html, 'javascript:' )
			|| false !== stripos( $nested_html, 'onclick' )
			|| false !== stripos( $nested_html, 'background-image' )
			|| false === strpos( $nested_html, 'style="color:red"' )
			|| false === strpos( $nested_html, 'data-safe="yes"' )
			|| false !== stripos( $value_html, 'javascript:' )
			|| false !== stripos( $value_html, 'onclick' )
			|| false === strpos( $value_html, 'https://example.test/' . $token )
		) {
			$failures[] = array(
				'label'      => 'block attribute HTML string leaves remove dangerous URI/style/event tokens and preserve safe markers',
				'linkHtml'   => self::preview( $link_html ),
				'nestedHtml' => self::preview( $nested_html ),
				'valueHtml'  => self::preview( $value_html ),
			);
		}
		if (
			! array_key_exists( 'badKey', $value_actual )
			|| array_key_exists( '<script>bad</script>Key', $value_actual )
			|| 42 !== ( $value_actual['nested']['number'] ?? null )
			|| true !== ( $value_actual['nested']['flag'] ?? null )
			|| null !== ( $value_actual['nested']['nothing'] ?? null )
			|| '<a href="mailto:test@example.test">Mail</a>' !== ( $value_actual['nested']['mailto'] ?? null )
		) {
			$failures[] = array(
				'label' => 'recursive block attribute filtering sanitizes keys and preserves non-string scalar leaves',
				'actual' => $value_actual,
			);
		}
		if (
			$template_expected !== $template_actual
			|| '' !== ( $template_actual['tagName'] ?? null )
			|| 'main' !== ( $template_actual['nested']['tagName'] ?? null )
		) {
			$failures[] = array(
				'label'    => 'core/template-part tagName values are restricted to allowed tag names',
				'expected' => $template_expected,
				'actual'   => $template_actual,
			);
		}
		if ( $hooked !== $hook_expected ) {
			$failures[] = array(
				'label'    => 'wp_pre_kses_block_attributes hook path agrees with explicit block-content filtering',
				'expected' => self::preview( $hook_expected ),
				'actual'   => self::preview( $hooked ),
			);
		}
		if (
			( ! $hook_added && $hook_signature_before !== $hook_signature_after )
			|| $hook_signature_before !== $hook_signature_restored
			|| ( ! $hook_added && $hook_priority_before !== $hook_priority_after )
			|| ( false !== $hook_priority_before && 10 !== $hook_priority_before )
		) {
			$failures[] = array(
				'label'  => 'pre_kses block attribute hook state is restored after hook-path check',
				'before' => $hook_priority_before,
				'after'  => $hook_priority_after,
				'added'  => $hook_added,
				'beforeSignature' => $hook_signature_before,
				'afterSignature'  => $hook_signature_after,
				'restoredSignature' => $hook_signature_restored,
			);
		}

		$details = array(
			'token'             => $token,
			'serializedPreview' => self::preview( $serialized ),
			'filteredPreview'   => self::preview( $content_filtered ),
			'hookPriority'      => $hook_priority_before,
			'hookAdded'         => $hook_added,
			'hookRestored'      => $hook_signature_before === $hook_signature_restored,
			'failureCount'      => count( $failures ),
			'failures'          => array_slice( $failures, 0, 6 ),
		);

		if ( empty( $failures ) ) {
			return self::pass( $seed, null, 'kses.block-attribute-filtering', $serialized, $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.block-attribute-filtering',
			$serialized,
			'serialized block attributes and recursive values are KSES-filtered with stable hook cleanup',
			$failures,
			$details
		);
	}

	private static function check_pdf_object_policy_matrix( int $seed ): array {
		$upload_url        = 'https://component-fuzz.example:9443/uploads';
		$valid_https_url   = 'https://component-fuzz.example:9443/cat/foo.pdf';
		$valid_http_url    = 'http://component-fuzz.example:9443/cat/foo.pdf';
		$upload_dir_filter = static function ( array $uploads ) use ( $upload_url ): array {
			$uploads['path']    = '/tmp/component-fuzz-kses-uploads';
			$uploads['url']     = $upload_url;
			$uploads['subdir']  = '';
			$uploads['basedir'] = '/tmp/component-fuzz-kses-uploads';
			$uploads['baseurl'] = $upload_url;
			$uploads['error']   = false;
			return $uploads;
		};

		$cases = array(
			'validHttpsPort'           => array(
				'<object type="application/pdf" data="' . $valid_https_url . '" />',
				'<object type="application/pdf" data="' . $valid_https_url . '" />',
			),
			'validHttpPort'            => array(
				'<object type="application/pdf" data="' . $valid_http_url . '" />',
				'<object type="application/pdf" data="' . $valid_http_url . '" />',
			),
			'typeValueCaseInsensitive' => array(
				'<object type="APPLICATION/PDF" data="' . $valid_https_url . '" />',
				'<object type="APPLICATION/PDF" data="' . $valid_https_url . '" />',
			),
			'dataBadProtocolFilteredBeforeCallback' => array(
				'<object type="application/pdf" data="javascript:' . $valid_https_url . '" />',
				'<object type="application/pdf" data="' . $valid_https_url . '" />',
			),
			'duplicateTypeLastInvalid' => array(
				'<object type="application/pdf" type="application/exe" data="' . $valid_https_url . '" />',
				'<object type="application/pdf" data="' . $valid_https_url . '" />',
			),
			'duplicateTypeFirstInvalid' => array(
				'<object type="application/exe" type="application/pdf" data="' . $valid_https_url . '" />',
				'',
			),
			'queryStringRejected'      => array(
				'<object type="application/pdf" data="' . $valid_https_url . '?download=.pdf" />',
				'',
			),
			'fragmentRejected'         => array(
				'<object type="application/pdf" data="' . $valid_https_url . '#page.pdf" />',
				'',
			),
			'wrongExtensionRejected'   => array(
				'<object type="application/pdf" data="https://component-fuzz.example:9443/cat/foo.php" />',
				'',
			),
			'uppercaseExtensionRejected' => array(
				'<object type="application/pdf" data="https://component-fuzz.example:9443/cat/foo.PDF" />',
				'',
			),
			'nonSelfInvalidDegradesToBareTag' => array(
				'<object type="application/pdf" data="https://component-fuzz.example:9443/cat/foo.php"></object>',
				'<object></object>',
			),
			'wrongPortRejected'        => array(
				'<object type="application/pdf" data="https://component-fuzz.example:9444/cat/foo.pdf" />',
				'',
			),
			'missingPortRejected'      => array(
				'<object type="application/pdf" data="https://component-fuzz.example/cat/foo.pdf" />',
				'',
			),
			'protocolRelativeRejected' => array(
				'<object type="application/pdf" data="//component-fuzz.example:9443/cat/foo.pdf" />',
				'',
			),
		);

		$actual        = array();
		$helper_actual = array();
		\add_filter( 'upload_dir', $upload_dir_filter, 10, 1 );
		try {
			foreach ( $cases as $label => $case ) {
				$actual[ $label ] = \wp_kses_post( $case[0] );
			}

			$helper_actual = array(
				'validHttps'     => \_wp_kses_allow_pdf_objects( $valid_https_url ),
				'validHttp'      => \_wp_kses_allow_pdf_objects( $valid_http_url ),
				'queryString'    => \_wp_kses_allow_pdf_objects( $valid_https_url . '?download=.pdf' ),
				'fragment'       => \_wp_kses_allow_pdf_objects( $valid_https_url . '#page.pdf' ),
				'wrongExtension' => \_wp_kses_allow_pdf_objects( 'https://component-fuzz.example:9443/cat/foo.php' ),
				'uppercaseExtension' => \_wp_kses_allow_pdf_objects( 'https://component-fuzz.example:9443/cat/foo.PDF' ),
				'wrongPort'      => \_wp_kses_allow_pdf_objects( 'https://component-fuzz.example:9444/cat/foo.pdf' ),
				'missingPort'    => \_wp_kses_allow_pdf_objects( 'https://component-fuzz.example/cat/foo.pdf' ),
			);
		} finally {
			\remove_filter( 'upload_dir', $upload_dir_filter, 10 );
		}

		$expected        = array();
		$helper_expected = array(
			'validHttps'     => true,
			'validHttp'      => true,
			'queryString'    => false,
			'fragment'       => false,
			'wrongExtension' => false,
			'uppercaseExtension' => false,
			'wrongPort'      => false,
			'missingPort'    => false,
		);
		foreach ( $cases as $label => $case ) {
			$expected[ $label ] = $case[1];
		}

		$filter_removed = false === \has_filter( 'upload_dir', $upload_dir_filter );
		$details        = array(
			'uploadUrl'      => $upload_url,
			'actual'         => self::compact_value( $actual ),
			'helperActual'   => $helper_actual,
			'filterRemoved'  => $filter_removed,
			'caseCount'      => count( $cases ),
		);

		if ( $expected === $actual && $helper_expected === $helper_actual && $filter_removed ) {
			return self::pass( $seed, null, 'wp_kses_post.pdf-object-policy-matrix', '<object type="application/pdf" data>', $details );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_post.pdf-object-policy-matrix',
			'<object type="application/pdf" data>',
			array(
				'outputs' => $expected,
				'helper'  => $helper_expected,
			),
			array(
				'outputs' => $actual,
				'helper'  => $helper_actual,
			),
			$details
		);
	}

	private static function check_uri_attribute_filter_scope( int $seed ): array {
		$attrs       = 'href="javascript:alert(1)" data-url="javascript:alert(1)" data-plain="javascript:alert(1)" src="https://example.test/image.png"';
		$protocols   = array( 'http', 'https' );
		$uri_filter  = static function ( array $uri_attributes ): array {
			$uri_attributes[] = 'data-url';
			return array_values( array_unique( $uri_attributes ) );
		};
		$without     = \wp_kses_hair( $attrs, $protocols );
		$with_filter = array();
		\add_filter( 'wp_kses_uri_attributes', $uri_filter, 10, 1 );
		try {
			$with_filter = \wp_kses_hair( $attrs, $protocols );
		} finally {
			\remove_filter( 'wp_kses_uri_attributes', $uri_filter, 10 );
		}
		$after_filter   = \wp_kses_hair( $attrs, $protocols );
		$filter_removed = false === \has_filter( 'wp_kses_uri_attributes', $uri_filter );
		$actual         = array(
			'withoutHref'      => $without['href']['value'] ?? null,
			'withoutDataUrl'   => $without['data-url']['value'] ?? null,
			'withoutDataPlain' => $without['data-plain']['value'] ?? null,
			'withHref'         => $with_filter['href']['value'] ?? null,
			'withDataUrl'      => $with_filter['data-url']['value'] ?? null,
			'withDataPlain'    => $with_filter['data-plain']['value'] ?? null,
			'afterDataUrl'     => $after_filter['data-url']['value'] ?? null,
			'filterRemoved'    => $filter_removed,
		);
		$expected       = array(
			'withoutHref'      => 'alert(1)',
			'withoutDataUrl'   => 'javascript:alert(1)',
			'withoutDataPlain' => 'javascript:alert(1)',
			'withHref'         => 'alert(1)',
			'withDataUrl'      => 'alert(1)',
			'withDataPlain'    => 'javascript:alert(1)',
			'afterDataUrl'     => 'javascript:alert(1)',
			'filterRemoved'    => true,
		);
		$details        = array(
			'attrs'        => $attrs,
			'without'      => self::compact_value( $without ),
			'withFilter'   => self::compact_value( $with_filter ),
			'afterFilter'  => self::compact_value( $after_filter ),
			'actualValues' => $actual,
		);

		if ( $expected === $actual ) {
			return self::pass( $seed, null, 'wp_kses_uri_attributes.filter-controls-custom-data-url', $attrs, $details );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_uri_attributes.filter-controls-custom-data-url',
			$attrs,
			$expected,
			$actual,
			$details
		);
	}

	private static function check_attribute_constraint_invariants( int $seed ): array {
		foreach ( array( 'add_filter', 'remove_filter', 'has_filter', 'wp_kses', 'wp_kses_attr', 'wp_kses_attr_check', 'wp_kses_attr_parse', 'wp_kses_check_attr_val', 'safecss_filter_attr' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						null,
						'kses.attribute-constraint-helpers.available',
						$function_name . '() is not loaded',
						''
					),
				);
			}
		}

		$results = array();
		try {
			$results[] = self::check_attr_val_constraint_truth_table( $seed );
			$results[] = self::check_attr_check_constraint_mutations( $seed );
			$results[] = self::check_required_attr_tag_stripping( $seed );
			$results[] = self::check_style_attr_entity_decoding( $seed );
			$results[] = self::check_hair_exact_fixture_matrix( $seed );
			$results[] = self::check_attr_parse_round_trips( $seed );
			$results[] = self::check_safecss_allow_css_filter( $seed );
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, null, 'kses.attribute-constraint-invariants-no-throw', '', $e );
		}

		return $results;
	}

	private static function check_attr_val_constraint_truth_table( int $seed ): array {
		$cases = array(
			array( 'maxlen-pass', 'abc', 'n', 'maxlen', 3, true ),
			array( 'maxlen-fail', 'abcd', 'n', 'maxlen', 3, false ),
			array( 'minlen-pass', 'abc', 'n', 'minlen', 3, true ),
			array( 'minlen-fail', 'ab', 'n', 'minlen', 3, false ),
			array( 'maxval-pass', ' 123 ', 'n', 'maxval', 200, true ),
			array( 'maxval-over-limit', '201', 'n', 'maxval', 200, false ),
			array( 'maxval-too-many-digits', '1234567', 'n', 'maxval', 2000000, false ),
			array( 'minval-pass', '7', 'n', 'minval', 5, true ),
			array( 'minval-under-limit', '4', 'n', 'minval', 5, false ),
			array( 'valueless-required-pass', '', 'y', 'valueless', 'y', true ),
			array( 'valueless-required-fail', 'disabled', 'n', 'valueless', 'y', false ),
			array( 'valued-required-pass', 'submit', 'n', 'valueless', 'n', true ),
			array( 'values-case-insensitive-pass', 'BETA', 'n', 'values', array( 'alpha', 'beta' ), true ),
			array( 'values-fail', 'gamma', 'n', 'values', array( 'alpha', 'beta' ), false ),
			array(
				'value-callback-pass',
				'prefix-ok',
				'n',
				'value_callback',
				static function ( string $value ): bool {
					return str_starts_with( $value, 'prefix-' );
				},
				true,
			),
			array(
				'value-callback-fail',
				'other',
				'n',
				'value_callback',
				static function ( string $value ): bool {
					return str_starts_with( $value, 'prefix-' );
				},
				false,
			),
		);

		$failures = array();
		foreach ( $cases as $case ) {
			list( $label, $value, $vless, $check_name, $check_value, $expected ) = $case;
			$actual = \wp_kses_check_attr_val( $value, $vless, $check_name, $check_value );
			if ( $expected !== $actual ) {
				$failures[] = array(
					'label'    => $label,
					'value'    => $value,
					'vless'    => $vless,
					'check'    => $check_name,
					'expected' => $expected,
					'actual'   => $actual,
				);
			}
		}

		if ( array() === $failures ) {
			return self::pass( $seed, null, 'wp_kses_check_attr_val.constraint-truth-table', 'max/min/value constraints', array( 'cases' => count( $cases ) ) );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_check_attr_val.constraint-truth-table',
			'max/min/value constraints',
			'all generated attribute value constraints match their expected branch behavior',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_attr_check_constraint_mutations( int $seed ): array {
		$allowed_html = array(
			'button' => array(
				'data-*'   => array( 'maxlen' => 6 ),
				'disabled' => array( 'valueless' => 'y' ),
				'role'     => array( 'values' => array( 'button', 'navigation' ) ),
			),
		);
		$cases        = array(
			array( 'valid-data-wildcard', 'data-safe', 'abc123', 'data-safe="abc123"', 'n', true, 'data-safe', 'abc123', 'data-safe="abc123"' ),
			array( 'invalid-data-wildcard-name', 'data-bad.dot', 'abc', 'data-bad.dot="abc"', 'n', false, '', '', '' ),
			array( 'invalid-data-wildcard-length', 'data-safe', 'abcdefg', 'data-safe="abcdefg"', 'n', false, '', '', '' ),
			array( 'valid-role-values', 'role', 'Button', 'role="Button"', 'n', true, 'role', 'Button', 'role="Button"' ),
			array( 'invalid-role-values', 'role', 'dialog', 'role="dialog"', 'n', false, '', '', '' ),
			array( 'valid-valueless', 'disabled', '', 'disabled', 'y', true, 'disabled', '', 'disabled' ),
			array( 'invalid-valueless-with-value', 'disabled', 'disabled', 'disabled="disabled"', 'n', false, '', '', '' ),
		);

		$failures = array();
		foreach ( $cases as $case ) {
			list( $label, $name, $value, $whole, $vless, $expected_allowed, $expected_name, $expected_value, $expected_whole ) = $case;
			$actual_name  = $name;
			$actual_value = $value;
			$actual_whole = $whole;
			$allowed      = \wp_kses_attr_check( $actual_name, $actual_value, $actual_whole, $vless, 'button', $allowed_html );

			if (
				$expected_allowed !== $allowed
				|| $expected_name !== $actual_name
				|| $expected_value !== $actual_value
				|| $expected_whole !== $actual_whole
			) {
				$failures[] = array(
					'label'    => $label,
					'expected' => array( $expected_allowed, $expected_name, $expected_value, $expected_whole ),
					'actual'   => array( $allowed, $actual_name, $actual_value, $actual_whole ),
				);
			}
		}

		if ( array() === $failures ) {
			return self::pass( $seed, null, 'wp_kses_attr_check.constraint-mutations', 'button data/role/disabled attributes', array( 'cases' => count( $cases ) ) );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_attr_check.constraint-mutations',
			'button data/role/disabled attributes',
			'accepted attributes stay intact and rejected attributes are cleared by reference',
			$failures,
			array( 'cases' => count( $cases ) )
		);
	}

	private static function check_required_attr_tag_stripping( int $seed ): array {
		$allowed_html = array(
			'mark' => array(
				'data-required' => array(
					'required' => true,
					'values'   => array( 'keep' ),
				),
				'data-optional' => true,
			),
		);
		$protocols    = self::default_protocols();
		$actual       = array(
			'valid'        => \wp_kses_attr( 'mark', ' data-required="keep" data-optional="ok"', $allowed_html, $protocols ),
			'missing'      => \wp_kses_attr( 'mark', ' data-optional="ok"', $allowed_html, $protocols ),
			'invalidSelf'  => \wp_kses_attr( 'mark', ' data-required="drop" /', $allowed_html, $protocols ),
			'missingSelf'  => \wp_kses_attr( 'mark', ' data-optional="ok" /', $allowed_html, $protocols ),
		);
		$expected     = array(
			'valid'       => '<mark data-required="keep" data-optional="ok">',
			'missing'     => '<mark>',
			'invalidSelf' => '',
			'missingSelf' => '',
		);

		if ( $expected === $actual ) {
			return self::pass( $seed, null, 'wp_kses_attr.required-attribute-tag-stripping', 'mark data-required', array( 'outputs' => $actual ) );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_attr.required-attribute-tag-stripping',
			'mark data-required',
			$expected,
			$actual
		);
	}

	private static function check_hair_exact_fixture_matrix( int $seed ): array {
		if ( ! function_exists( 'wp_kses_hair' ) ) {
			return self::skip(
				$seed,
				null,
				'wp_kses_hair.exact-core-fixture-matrix.available',
				'wp_kses_hair() is not loaded',
				''
			);
		}

		$default_protocols = self::default_protocols();
		$cases             = array(
			'empty attributes' => array(
				'input'    => '',
				'expected' => array(),
			),
			'prematurely terminated attributes' => array(
				'input'    => '>',
				'expected' => array(),
			),
			'prematurely terminated malformed attributes' => array(
				'input'    => 'foo>bar="baz"',
				'expected' => array(
					'foo' => self::hair_bool_attr( 'foo' ),
				),
			),
			'mixed quotes and unquoted values' => array(
				'input'    => 'title="double" alt=\'single\' id=unquoted',
				'expected' => array(
					'title' => self::hair_value_attr( 'title', 'double' ),
					'alt'   => self::hair_value_attr( 'alt', 'single' ),
					'id'    => self::hair_value_attr( 'id', 'unquoted' ),
				),
			),
			'entity normalization' => array(
				'input'    => 'title="&#x3C;HEX&#X3E;" data-bad="&invalid; &#; &#x;"',
				'expected' => array(
					'title'    => self::hair_value_attr( 'title', '&lt;HEX&gt;' ),
					'data-bad' => self::hair_value_attr( 'data-bad', '&amp;invalid; &amp;#; &amp;#x;' ),
				),
			),
			'duplicate attributes first wins' => array(
				'input'    => 'id="first" class="test" id="second"',
				'expected' => array(
					'id'    => self::hair_value_attr( 'id', 'first' ),
					'class' => self::hair_value_attr( 'class', 'test' ),
				),
			),
			'malformed unclosed double quote' => array(
				'input'    => 'title="unclosed class="test"',
				'expected' => array(
					'title' => self::hair_value_attr( 'title', 'unclosed class=' ),
					'test"' => self::hair_bool_attr( 'test"' ),
				),
			),
			'attribute names with colons and dots' => array(
				'input'    => 'xml:lang="en" data.value="test" xlink:href="#anchor"',
				'expected' => array(
					'xml:lang'   => self::hair_value_attr( 'xml:lang', 'en' ),
					'data.value' => self::hair_value_attr( 'data.value', 'test' ),
					'xlink:href' => self::hair_value_attr( 'xlink:href', '#anchor' ),
				),
			),
			'invalid attribute name special chars' => array(
				'input'    => '@invalid="value" $bad="value"',
				'expected' => array(
					'@invalid' => self::hair_value_attr( '@invalid', 'value' ),
					'$bad'     => self::hair_value_attr( '$bad', 'value' ),
				),
			),
			'forward slashes between attributes' => array(
				'input'    => 'att / att2=2 /// att3="3"',
				'expected' => array(
					'att'  => self::hair_bool_attr( 'att' ),
					'att2' => self::hair_value_attr( 'att2', '2' ),
					'att3' => self::hair_value_attr( 'att3', '3' ),
				),
			),
			'spaces around equals' => array(
				'input'    => 'id = "spaced" class ="left" title= "right"',
				'expected' => array(
					'id'    => self::hair_value_attr( 'id', 'spaced' ),
					'class' => self::hair_value_attr( 'class', 'left' ),
					'title' => self::hair_value_attr( 'title', 'right' ),
				),
			),
			'multiple equals signs' => array(
				'input'    => 'att=="val"',
				'expected' => array(
					'att' => self::hair_value_attr( 'att', '=&quot;val&quot;' ),
				),
			),
			'equals echo pattern' => array(
				'input'    => "att==echo 'something'",
				'expected' => array(
					'att'         => self::hair_value_attr( 'att', '=echo' ),
					"'something'" => self::hair_bool_attr( "'something'" ),
				),
			),
			'attribute starting with equals' => array(
				'input'    => '= bool k=v',
				'expected' => array(
					'='    => self::hair_bool_attr( '=' ),
					'bool' => self::hair_bool_attr( 'bool' ),
					'k'    => self::hair_value_attr( 'k', 'v' ),
				),
			),
			'triple equals quoted whitespace' => array(
				'input'    => '==="  "',
				'expected' => array(
					'=' => self::hair_value_attr( '=', '=&quot;' ),
					'"' => self::hair_bool_attr( '"' ),
				),
			),
			'empty attribute name with value' => array(
				'input'    => '="value" class="test"',
				'expected' => array(
					'="value"' => self::hair_bool_attr( '="value"' ),
					'class'    => self::hair_value_attr( 'class', 'test' ),
				),
			),
			'uri protocol filtering and non-uri preservation' => array(
				'input'    => 'href="javascript:alert(1)" data-url="javascript:alert(1)" src="data:text/html,<script>alert(1)</script>"',
				'expected' => array(
					'href'     => self::hair_value_attr( 'href', 'alert(1)' ),
					'data-url' => self::hair_value_attr( 'data-url', 'javascript:alert(1)' ),
					'src'      => self::hair_value_attr( 'src', 'text/html,&lt;script&gt;alert(1)&lt;/script&gt;' ),
				),
			),
			'custom allowed protocols' => array(
				'input'     => 'href="gopher://gopher.example.org"',
				'protocols' => array( 'gopher' ),
				'expected'  => array(
					'href' => self::hair_value_attr( 'href', 'gopher://gopher.example.org' ),
				),
			),
		);

		$failures = array();
		foreach ( $cases as $label => $case ) {
			$actual = \wp_kses_hair( $case['input'], $case['protocols'] ?? $default_protocols );
			if ( $case['expected'] !== $actual ) {
				$failures[] = array(
					'label'    => $label,
					'input'    => $case['input'],
					'expected' => $case['expected'],
					'actual'   => $actual,
				);
			}
		}

		$details = array(
			'cases'    => count( $cases ),
			'failures' => array_slice( $failures, 0, 6 ),
		);

		if ( array() === $failures ) {
			return self::pass( $seed, null, 'wp_kses_hair.exact-core-fixture-matrix', 'core wpKsesHair fixtures', $details );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_hair.exact-core-fixture-matrix',
			'core wpKsesHair fixtures',
			'exact parsed attribute arrays for representative Core parser fixtures',
			$failures,
			$details
		);
	}

	private static function hair_value_attr( string $name, string $value ): array {
		return array(
			'name'  => $name,
			'value' => $value,
			'whole' => $name . '="' . $value . '"',
			'vless' => 'n',
		);
	}

	private static function hair_bool_attr( string $name ): array {
		return array(
			'name'  => $name,
			'value' => '',
			'whole' => $name,
			'vless' => 'y',
		);
	}

	private static function check_style_attr_entity_decoding( int $seed ): array {
		$input        = '<div style="background-image: url(&quot;https://localhost/image.jpg&quot;);"></div>';
		$allowed_html = array(
			'div' => array(
				'style' => true,
			),
		);
		$actual       = \wp_kses( $input, $allowed_html );
		$expected     = '<div style="background-image: url(&quot;https://localhost/image.jpg&quot;)"></div>';

		if ( $expected === $actual ) {
			return self::pass( $seed, null, 'wp_kses_attr.style-decodes-entities-before-css-filtering', $input, array( 'output' => self::preview( $actual ) ) );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_attr.style-decodes-entities-before-css-filtering',
			$input,
			$expected,
			$actual
		);
	}

	private static function check_attr_parse_round_trips( int $seed ): array {
		$valid        = '<a href="https://example.test/path" title=\'A B\' data-x=ok />';
		$valid_parsed = \wp_kses_attr_parse( $valid );
		$actual       = array(
			'validRoundTrip' => is_array( $valid_parsed ) ? implode( '', $valid_parsed ) : $valid_parsed,
			'closingTag'     => \wp_kses_attr_parse( '</a>' ),
			'malformedTag'   => \wp_kses_attr_parse( '<a href="unterminated>' ),
		);
		$expected     = array(
			'validRoundTrip' => $valid,
			'closingTag'     => false,
			'malformedTag'   => false,
		);

		if ( $expected === $actual ) {
			return self::pass( $seed, null, 'wp_kses_attr_parse.full-tag-round-trip-and-rejects-invalid', $valid, array( 'parsed' => self::compact_value( $valid_parsed ) ) );
		}

		return self::fail(
			$seed,
			null,
			'wp_kses_attr_parse.full-tag-round-trip-and-rejects-invalid',
			$valid,
			$expected,
			$actual
		);
	}

	private static function check_safecss_allow_css_filter( int $seed ): array {
		$css            = 'height: expression( body.scrollTop + 50 + "px" );margin-bottom: 2px};color:red';
		$without_filter = \safecss_filter_attr( $css );
		$seen           = array();
		$allow_filter   = static function ( bool $allow_css, string $css_test_string ) use ( &$seen ): bool {
			$seen[] = array(
				'default' => $allow_css,
				'test'    => $css_test_string,
			);
			return true;
		};

		\add_filter( 'safecss_filter_attr_allow_css', $allow_filter, 10, 2 );
		try {
			$with_filter = \safecss_filter_attr( $css );
		} finally {
			\remove_filter( 'safecss_filter_attr_allow_css', $allow_filter, 10 );
		}

		$filter_removed = false === \has_filter( 'safecss_filter_attr_allow_css', $allow_filter );
		$ok             = 'color:red' === $without_filter
			&& false !== strpos( $with_filter, 'height: expression( body.scrollTop + 50 + "px" )' )
			&& false !== strpos( $with_filter, 'margin-bottom: 2px}' )
			&& false !== strpos( $with_filter, 'color:red' )
			&& 3 === count( $seen )
			&& false === $seen[0]['default']
			&& false === $seen[1]['default']
			&& true === $seen[2]['default']
			&& $filter_removed;
		$details        = array(
			'withoutFilter' => self::preview( $without_filter ),
			'withFilter'    => self::preview( $with_filter ),
			'seen'          => $seen,
			'filterRemoved' => $filter_removed,
		);

		if ( $ok ) {
			return self::pass( $seed, null, 'safecss_filter_attr_allow_css.unsafe-token-gate-and-restoration', $css, $details );
		}

		return self::fail(
			$seed,
			null,
			'safecss_filter_attr_allow_css.unsafe-token-gate-and-restoration',
			$css,
			'unsafe CSS tokens removed by default, restored by scoped allow filter, and filter removed',
			$details,
			$details
		);
	}

	private static function check_wp_kses_invariants( int $seed, int $case_index, array $case ): array {
		$results   = array();
		$html      = $case['html'];
		$protocols = $case['protocols'];
		$policy    = self::strict_policy();

		try {
			$filtered = \wp_kses( $html, $policy, $protocols );
			$again    = \wp_kses( $filtered, $policy, $protocols );

			if ( $filtered === $again ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.strict-policy-idempotent', $html, self::case_details( $case, $filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.strict-policy-idempotent',
					$html,
					$filtered,
					$again,
					self::case_details( $case )
				);
			}

			$control = self::raw_control_violation( $filtered );
			if ( null === $control ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.strict-policy-no-raw-c0-controls', $html, self::case_details( $case, $filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.strict-policy-no-raw-c0-controls',
					$html,
					'no raw C0 controls except tab, LF, and CR',
					$control,
					self::case_details( $case, $filtered )
				);
			}

			$violations = self::policy_violations( $filtered, $policy, $protocols );
			$violations = array_merge( $violations, self::attribute_boundary_violations( $filtered ) );
			$violations = array_merge( $violations, self::comment_syntax_violations( $filtered ) );
			if ( empty( $violations ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.strict-policy-enforced', $html, self::case_details( $case, $filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.strict-policy-enforced',
					$html,
					'only tags, attributes, and URI protocols from the strict policy',
					$violations,
					self::case_details( $case, $filtered )
				);
			}

			$missing_markers = self::missing_text_markers( $filtered, $case );
			if ( empty( $missing_markers ) && self::strict_marker_attribute_retained( $filtered, $case ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.strict-policy-retains-allowed-text-and-marker-attrs', $html, self::case_details( $case, $filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.strict-policy-retains-allowed-text-and-marker-attrs',
					$html,
					'generated allowed text markers and their data-* boundary attributes remain in strict output',
					array(
						'missingMarkers' => $missing_markers,
						'markerAttr'     => self::marker_attribute_expectation( $case ),
						'output'         => self::preview( $filtered ),
					),
					self::case_details( $case )
				);
			}

			$post_again = \wp_kses( $filtered, 'post', $protocols );
			if ( $post_again === $filtered ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.policy-monotonicity-strict-output-survives-post', $html, self::case_details( $case, $filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.policy-monotonicity-strict-output-survives-post',
					$html,
					'strict policy output remains unchanged under broader post policy',
					array(
						'strictOutput' => self::preview( $filtered ),
						'postOutput'   => self::preview( $post_again ),
					),
					self::case_details( $case )
				);
			}

			$data_filtered = \wp_kses( $html, 'data', $protocols );
			$data_post     = \wp_kses( $data_filtered, 'post', $protocols );
			if ( $data_post === $data_filtered ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.policy-monotonicity-data-output-survives-post', $html, self::case_details( $case, $data_filtered ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.policy-monotonicity-data-output-survives-post',
					$html,
					'data policy output remains unchanged under broader post policy',
					array(
						'dataOutput' => self::preview( $data_filtered ),
						'postOutput' => self::preview( $data_post ),
					),
					self::case_details( $case )
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'wp_kses.core-invariants-no-throw', $html, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_wp_kses_post_invariants( int $seed, int $case_index, array $case ): array {
		$results = array();
		$html    = $case['html'];

		if ( ! function_exists( 'wp_kses_post' ) ) {
			return array( self::skip( $seed, $case_index, 'wp_kses_post.available', 'wp_kses_post() is not loaded', $html ) );
		}

		try {
			$post   = \wp_kses_post( $html );
			$direct = \wp_kses( $html, 'post' );

			if ( $post === $direct ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.equals-wp_kses-post-policy', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.equals-wp_kses-post-policy',
					$html,
					'direct wp_kses($html, "post") output',
					array(
						'wpKsesPost' => self::preview( $post ),
						'wpKsesPostPolicy' => self::preview( $direct ),
					),
					self::case_details( $case )
				);
			}

			$again = \wp_kses_post( $post );
			if ( $post === $again ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.idempotent', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.idempotent',
					$html,
					$post,
					$again,
					self::case_details( $case )
				);
			}

			$violations = self::post_output_security_violations( $post, self::default_protocols() );
			$violations = array_merge( $violations, self::style_attribute_violations( $post, self::default_protocols() ) );
			$violations = array_merge( $violations, self::attribute_boundary_violations( $post ) );
			$violations = array_merge( $violations, self::comment_syntax_violations( $post ) );
			if ( empty( $violations ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.output-security-boundaries-safe', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.output-security-boundaries-safe',
					$html,
					'no forbidden URI protocols, unsafe style rules, event handlers, raw attribute boundaries, or malformed comments',
					$violations,
					self::case_details( $case, $post )
				);
			}

			$missing_markers = self::missing_text_markers( $post, $case );
			if ( empty( $missing_markers ) && self::strict_marker_attribute_retained( $post, $case ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.retains-allowed-text-and-marker-attrs', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.retains-allowed-text-and-marker-attrs',
					$html,
					'generated allowed text markers and their data-* boundary attributes remain in post output',
					array(
						'missingMarkers' => $missing_markers,
						'markerAttr'     => self::marker_attribute_expectation( $case ),
						'output'         => self::preview( $post ),
					),
					self::case_details( $case )
				);
			}

			$control = self::raw_control_violation( $post );
			if ( null === $control ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.no-raw-c0-controls', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.no-raw-c0-controls',
					$html,
					'no raw C0 controls except tab, LF, and CR',
					$control,
					self::case_details( $case, $post )
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_post.invariants-no-throw', $html, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_wp_kses_data_invariants( int $seed, int $case_index, array $case ): array {
		$html = $case['html'];
		foreach ( array( 'wp_kses_data', 'wp_filter_kses', 'add_filter', 'remove_filter', 'has_filter', 'apply_filters' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						$case_index,
						'wp_kses_data.wrapper-functions.available',
						$function_name . '() is not loaded',
						$html
					),
				);
			}
		}

		$results = array();
		try {
			$data    = \wp_kses_data( $html );
			$direct  = \wp_kses( $html, 'data' );
			$details = self::case_details( $case, $data );

			if ( $data === $direct ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_data.equals-wp_kses-data-outside-filter', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_data.equals-wp_kses-data-outside-filter',
					$html,
					'direct wp_kses($html, "data") output',
					array(
						'wpKsesData' => self::preview( $data ),
						'direct'     => self::preview( $direct ),
					),
					$details
				);
			}

			$violations = self::policy_violations( $data, \wp_kses_allowed_html( 'data' ), self::default_protocols() );
			$violations = array_merge( $violations, self::style_attribute_violations( $data, self::default_protocols() ) );
			$violations = array_merge( $violations, self::attribute_boundary_violations( $data ) );
			$violations = array_merge( $violations, self::comment_syntax_violations( $data ) );
			if ( empty( $violations ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_data.output-security-boundaries-safe', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_data.output-security-boundaries-safe',
					$html,
					'data-policy output contains only allowed tags/attributes with safe values and comments',
					$violations,
					$details
				);
			}

			$tag              = self::case_filter_tag( $seed, $case_index, 'data' );
			$slashed_tag      = self::case_filter_tag( $seed, $case_index, 'filter' );
			$current_before   = $GLOBALS['wp_current_filter'] ?? null;
			$filtered_data    = null;
			$filtered_slashed = null;

			\add_filter( $tag, 'wp_kses_data' );
			\add_filter( $slashed_tag, 'wp_filter_kses' );
			try {
				$filtered_data    = \apply_filters( $tag, $html );
				$slashed          = addslashes( $html );
				$filtered_slashed = \apply_filters( $slashed_tag, $slashed );
			} finally {
				\remove_filter( $tag, 'wp_kses_data' );
				\remove_filter( $slashed_tag, 'wp_filter_kses' );
			}

			$expected_data    = \wp_kses( $html, $tag );
			$expected_slashed = addslashes( \wp_kses( stripslashes( addslashes( $html ) ), $slashed_tag ) );
			$current_after    = $GLOBALS['wp_current_filter'] ?? null;
			$filter_details   = $details + array(
				'dataTag'        => $tag,
				'slashedTag'     => $slashed_tag,
				'dataPreview'    => self::preview( (string) $filtered_data ),
				'slashedPreview' => self::preview( (string) $filtered_slashed ),
			);

			if (
				$expected_data === $filtered_data
				&& $expected_slashed === $filtered_slashed
				&& false === \has_filter( $tag, 'wp_kses_data' )
				&& false === \has_filter( $slashed_tag, 'wp_filter_kses' )
				&& $current_before === $current_after
			) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_data.wp_filter_kses.current-filter-agreement-and-restoration', $html, $filter_details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_data.wp_filter_kses.current-filter-agreement-and-restoration',
					$html,
					'wrapper output agrees with current-filter wp_kses definitions and temporary filters are removed',
					array(
						'dataMatches'       => $expected_data === $filtered_data,
						'slashedMatches'    => $expected_slashed === $filtered_slashed,
						'dataFilterGone'    => false === \has_filter( $tag, 'wp_kses_data' ),
						'slashedFilterGone' => false === \has_filter( $slashed_tag, 'wp_filter_kses' ),
						'currentRestored'   => $current_before === $current_after,
					),
					$filter_details
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_data.wrapper-invariants-no-throw', $html, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_bad_protocol_invariants( int $seed, int $case_index, array $case ): array {
		$results   = array();
		$protocols = $case['protocols'];

		foreach ( $case['urls'] as $url_index => $url ) {
			try {
				$filtered = \wp_kses_bad_protocol( $url, $protocols );
				$again    = \wp_kses_bad_protocol( $filtered, $protocols );
				$label    = 'url#' . $url_index;
				$details  = array(
					'profile'   => $case['profile'],
					'urlIndex'  => $url_index,
					'protocols' => $protocols,
					'output'    => self::preview( $filtered ),
				);

				if ( $filtered === $again ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_bad_protocol.idempotent', $url, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_bad_protocol.idempotent',
						$url,
						$filtered,
						$again,
						$details
					);
				}

				$scheme = self::leading_scheme( $filtered );
				if ( null === $scheme || in_array( $scheme, self::lowercase_list( $protocols ), true ) ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_bad_protocol.allowed-leading-scheme-only', $url, $details + array( 'scheme' => $scheme, 'label' => $label ) );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_bad_protocol.allowed-leading-scheme-only',
						$url,
						'empty, relative, or leading scheme in allowed protocols',
						array(
							'scheme' => $scheme,
							'output' => self::preview( $filtered ),
						),
						$details
					);
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_bad_protocol.no-throw', $url, $e, self::case_details( $case ) );
			}
		}

		return $results;
	}

	private static function check_bad_protocol_helper_invariants( int $seed, int $case_index, array $case ): array {
		foreach ( array( 'wp_kses_bad_protocol_once', 'wp_kses_bad_protocol_once2', 'wp_kses_no_null' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						$case_index,
						'wp_kses_bad_protocol.helper-functions.available',
						$function_name . '() is not loaded',
						implode( "\n", $case['urls'] )
					),
				);
			}
		}

		$results   = array();
		$protocols = $case['protocols'];

		foreach ( $case['urls'] as $url_index => $url ) {
			try {
				$public   = \wp_kses_bad_protocol( $url, $protocols );
				$expected = self::bad_protocol_fixed_point( $url, $protocols );
				$once     = \wp_kses_bad_protocol_once( $public, $protocols );
				$details  = array(
					'profile'   => $case['profile'],
					'urlIndex'  => $url_index,
					'protocols' => $protocols,
					'public'    => self::preview( $public ),
					'once'      => self::preview( $once ),
				);

				if ( $public === $expected ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_bad_protocol.wrapper-agrees-with-once-fixed-point', $url, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_bad_protocol.wrapper-agrees-with-once-fixed-point',
						$url,
						$expected,
						$public,
						$details
					);
				}

				if ( $public === $once ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_bad_protocol_once.public-output-stable', $url, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_bad_protocol_once.public-output-stable',
						$url,
						'public wp_kses_bad_protocol() output remains unchanged after one helper pass',
						array(
							'public' => self::preview( $public ),
							'once'   => self::preview( $once ),
						),
						$details
					);
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_bad_protocol.helper-composition-no-throw', $url, $e, self::case_details( $case ) );
			}
		}

		$scheme_probes   = self::protocol_scheme_probes( $case );
		$scheme_failures = array();
		foreach ( $scheme_probes as $scheme_index => $scheme ) {
			try {
				$actual   = \wp_kses_bad_protocol_once2( $scheme, $protocols );
				$expected = self::bad_protocol_once2_expected( $scheme, $protocols );
				if ( $actual !== $expected ) {
					$scheme_failures[] = array(
						'index'    => $scheme_index,
						'scheme'   => self::preview( $scheme ),
						'expected' => $expected,
						'actual'   => $actual,
					);
				}
			} catch ( \Throwable $e ) {
				$scheme_failures[] = array(
					'index'     => $scheme_index,
					'scheme'    => self::preview( $scheme ),
					'throwable' => get_class( $e ) . ': ' . $e->getMessage(),
				);
			}
		}

		$scheme_details = self::case_details( $case ) + array(
			'protocols'   => $protocols,
			'schemeCount' => count( $scheme_probes ),
		);
		if ( empty( $scheme_failures ) ) {
			$results[] = self::pass( $seed, $case_index, 'wp_kses_bad_protocol_once2.scheme-normalization-contract', implode( "\n", $scheme_probes ), $scheme_details );
		} else {
			$results[] = self::fail(
				$seed,
				$case_index,
				'wp_kses_bad_protocol_once2.scheme-normalization-contract',
				implode( "\n", $scheme_probes ),
				'allowed normalized schemes return "scheme:" and disallowed schemes return an empty string',
				$scheme_failures,
				$scheme_details
			);
		}

		return $results;
	}

	private static function check_url_invariants( int $seed, int $case_index, array $case ): array {
		$results   = array();
		$protocols = $case['protocols'];

		if ( ! function_exists( 'esc_url' ) || ! function_exists( 'sanitize_url' ) ) {
			return array( self::skip( $seed, $case_index, 'esc_url.sanitize_url.available', 'esc_url() or sanitize_url() is not loaded', implode( "\n", $case['urls'] ) ) );
		}

		foreach ( $case['urls'] as $url_index => $url ) {
			try {
				$display  = \esc_url( $url, $protocols );
				$db       = \esc_url( $url, $protocols, 'db' );
				$sanitize = \sanitize_url( $url, $protocols );
				$details  = array(
					'profile'   => $case['profile'],
					'urlIndex'  => $url_index,
					'protocols' => $protocols,
					'display'   => self::preview( $display ),
					'db'        => self::preview( $db ),
					'sanitize'  => self::preview( $sanitize ),
				);

				if ( $sanitize === $db ) {
					$results[] = self::pass( $seed, $case_index, 'sanitize_url.equals-esc_url-db-context', $url, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'sanitize_url.equals-esc_url-db-context',
						$url,
						$db,
						$sanitize,
						$details
					);
				}

				foreach ( array( 'esc_url' => $display, 'sanitize_url' => $sanitize ) as $function_name => $output ) {
					$safe = '' === $output || self::url_is_safe( $output, $protocols );
					if ( $safe ) {
						$results[] = self::pass( $seed, $case_index, $function_name . '.output-protocol-safe', $url, $details + array( 'function' => $function_name ) );
					} else {
						$results[] = self::fail(
							$seed,
							$case_index,
							$function_name . '.output-protocol-safe',
							$url,
							'empty, relative, or allowed URL scheme',
							array(
								'output' => self::preview( $output ),
								'scheme' => self::leading_scheme( $output ),
							),
							$details
						);
					}

					$control = self::raw_control_violation( $output );
					if ( null === $control ) {
						$results[] = self::pass( $seed, $case_index, $function_name . '.no-raw-c0-controls', $url, $details + array( 'function' => $function_name ) );
					} else {
						$results[] = self::fail(
							$seed,
							$case_index,
							$function_name . '.no-raw-c0-controls',
							$url,
							'no raw C0 controls except tab, LF, and CR',
							$control,
							$details
						);
					}
				}

				$sanitize_again = \sanitize_url( $sanitize, $protocols );
				if ( $sanitize === $sanitize_again ) {
					$results[] = self::pass( $seed, $case_index, 'sanitize_url.idempotent', $url, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'sanitize_url.idempotent',
						$url,
						$sanitize,
						$sanitize_again,
						$details
					);
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'esc_url.sanitize_url.no-throw', $url, $e, self::case_details( $case ) );
			}
		}

		return $results;
	}

	private static function check_safecss_invariants( int $seed, int $case_index, array $case ): array {
		$css = $case['css'];
		if ( ! function_exists( 'safecss_filter_attr' ) ) {
			return array( self::skip( $seed, $case_index, 'safecss_filter_attr.available', 'safecss_filter_attr() is not loaded', $css ) );
		}

		$results = array();
		try {
			$filtered = \safecss_filter_attr( $css );
			$again    = \safecss_filter_attr( $filtered );
			$details  = self::case_details( $case, $filtered );

			if ( $filtered === $again ) {
				$results[] = self::pass( $seed, $case_index, 'safecss_filter_attr.idempotent', $css, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'safecss_filter_attr.idempotent',
					$css,
					$filtered,
					$again,
					$details
				);
			}

			$violations = self::css_policy_violations( $filtered, self::default_protocols() );
			if ( empty( $violations ) ) {
				$results[] = self::pass( $seed, $case_index, 'safecss_filter_attr.property-and-url-policy', $css, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'safecss_filter_attr.property-and-url-policy',
					$css,
					'only safe CSS properties and URL protocols remain',
					$violations,
					$details
				);
			}

			$control = self::raw_control_violation( $filtered );
			if ( null === $control ) {
				$results[] = self::pass( $seed, $case_index, 'safecss_filter_attr.no-raw-c0-controls', $css, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'safecss_filter_attr.no-raw-c0-controls',
					$css,
					'no raw C0 controls except tab, LF, and CR',
					$control,
					$details
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'safecss_filter_attr.no-throw', $css, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_nohtml_invariants( int $seed, int $case_index, array $case ): array {
		$html = $case['html'];
		if ( ! function_exists( 'wp_filter_nohtml_kses' ) ) {
			return array( self::skip( $seed, $case_index, 'wp_filter_nohtml_kses.available', 'wp_filter_nohtml_kses() is not loaded', $html ) );
		}

		$results = array();
		try {
			$slashed  = addslashes( $html );
			$filtered = \wp_filter_nohtml_kses( $slashed );
			$expected = addslashes( \wp_kses( stripslashes( $slashed ), 'strip' ) );
			$details  = self::case_details( $case, $filtered ) + array( 'slashedInputPreview' => self::preview( $slashed ) );

			if ( $expected === $filtered ) {
				$results[] = self::pass( $seed, $case_index, 'wp_filter_nohtml_kses.definition-equivalence', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_filter_nohtml_kses.definition-equivalence',
					$html,
					$expected,
					$filtered,
					$details
				);
			}

			if ( ! self::contains_html_like_tag( stripslashes( $filtered ) ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_filter_nohtml_kses.no-html-like-tags', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_filter_nohtml_kses.no-html-like-tags',
					$html,
					'no HTML-like tags after unslashing output',
					stripslashes( $filtered ),
					$details
				);
			}

			$again = \wp_filter_nohtml_kses( $filtered );
			if ( $filtered === $again ) {
				$results[] = self::pass( $seed, $case_index, 'wp_filter_nohtml_kses.idempotent-on-slashed-output', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_filter_nohtml_kses.idempotent-on-slashed-output',
					$html,
					$filtered,
					$again,
					$details
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'wp_filter_nohtml_kses.no-throw', $html, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_entity_invariants( int $seed, int $case_index, array $case ): array {
		$html = $case['html'];
		foreach ( array( 'wp_kses_normalize_entities', 'wp_kses_decode_entities' ) as $function_name ) {
			if ( ! function_exists( $function_name ) ) {
				return array(
					self::skip(
						$seed,
						$case_index,
						'wp_kses.entity-helpers.available',
						$function_name . '() is not loaded',
						$html
					),
				);
			}
		}

		$results = array();
		try {
			$normalized       = \wp_kses_normalize_entities( $html );
			$normalized_again = \wp_kses_normalize_entities( $normalized );
			$filtered         = \wp_kses( $html, self::strict_policy(), $case['protocols'] );
			$filtered_again   = \wp_kses_normalize_entities( $filtered );
			$details          = self::case_details( $case, $filtered ) + array(
				'normalizedPreview' => self::preview( $normalized ),
			);

			if ( $normalized === $normalized_again ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_normalize_entities.idempotent', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_normalize_entities.idempotent',
					$html,
					$normalized,
					$normalized_again,
					$details
				);
			}

			if ( $filtered === $filtered_again ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses.output-entities-normalized', $html, $details );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses.output-entities-normalized',
					$html,
					'strict wp_kses output is stable under entity normalization',
					array(
						'filtered'   => self::preview( $filtered ),
						'normalized' => self::preview( $filtered_again ),
					),
					$details
				);
			}
		} catch ( \Throwable $e ) {
			$results[] = self::throwable_result( $seed, $case_index, 'wp_kses.entity-invariants-no-throw', $html, $e, self::case_details( $case ) );
		}

		return $results;
	}

	private static function check_hair_parse_invariants( int $seed, int $case_index, array $case ): array {
		$attrs   = $case['attrs'];
		$results = array();

		if ( function_exists( 'wp_kses_hair_parse' ) ) {
			try {
				$parsed = \wp_kses_hair_parse( $attrs );
				$details = self::case_details( $case ) + array(
					'attributeInputPreview' => self::preview( $attrs ),
					'parsed'                => self::compact_value( $parsed ),
				);

				if ( false === $parsed ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_hair_parse.rejects-or-round-trips', $attrs, $details + array( 'parserResult' => false ) );
				} elseif ( is_array( $parsed ) && implode( '', $parsed ) === $attrs ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_hair_parse.rejects-or-round-trips', $attrs, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_hair_parse.rejects-or-round-trips',
						$attrs,
						'false or parsed segments that concatenate to the original attribute string',
						$parsed,
						$details
					);
				}

				if ( is_array( $parsed ) ) {
					$empty_segments = array();
					foreach ( $parsed as $segment_index => $segment ) {
						if ( ! is_string( $segment ) || '' === $segment ) {
							$empty_segments[] = array(
								'index' => $segment_index,
								'value' => $segment,
							);
						}
					}
					if ( empty( $empty_segments ) ) {
						$results[] = self::pass( $seed, $case_index, 'wp_kses_hair_parse.non-empty-string-segments', $attrs, $details );
					} else {
						$results[] = self::fail(
							$seed,
							$case_index,
							'wp_kses_hair_parse.non-empty-string-segments',
							$attrs,
							'array of non-empty strings or an empty array for empty input',
							$empty_segments,
							$details
						);
					}
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_hair_parse.no-throw', $attrs, $e, self::case_details( $case ) );
			}
		} else {
			$results[] = self::skip( $seed, $case_index, 'wp_kses_hair_parse.available', 'wp_kses_hair_parse() is not loaded', $attrs );
		}

		if ( function_exists( 'wp_kses_hair' ) ) {
			try {
				$hair       = \wp_kses_hair( $attrs, $case['protocols'] );
				$violations = self::hair_violations( $hair, $case['protocols'] );
				$details    = self::case_details( $case ) + array( 'hair' => self::compact_value( $hair ) );
				if ( empty( $violations ) ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_hair.shape-and-uri-policy', $attrs, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_hair.shape-and-uri-policy',
						$attrs,
						'structured attribute records with safe URI attribute values',
						$violations,
						$details
					);
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_hair.no-throw', $attrs, $e, self::case_details( $case ) );
			}
		} else {
			$results[] = self::skip( $seed, $case_index, 'wp_kses_hair.available', 'wp_kses_hair() is not loaded', $attrs );
		}

		return $results;
	}

	private static function check_one_attr_invariants( int $seed, int $case_index, array $case ): array {
		if ( ! function_exists( 'wp_kses_one_attr' ) ) {
			return array( self::skip( $seed, $case_index, 'wp_kses_one_attr.available', 'wp_kses_one_attr() is not loaded', $case['attrs'] ) );
		}

		$results = array();
		foreach ( self::interesting_attr_pieces( $case ) as $piece_index => $piece ) {
			try {
				$output   = \wp_kses_one_attr( $piece, $case['tag'] );
				$details  = self::case_details( $case, $output ) + array( 'pieceIndex' => $piece_index, 'tag' => $case['tag'] );
				$lower    = strtolower( ltrim( $output ) );
				$bad_name = 0 === strpos( $lower, 'on' );
				$bad_uri  = false;

				if ( 1 === preg_match( '/^\s*([A-Za-z0-9_:\.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/s', $output, $matches ) ) {
					$name  = strtolower( $matches[1] );
					$value = '';
					for ( $i = 2; $i <= 4; ++$i ) {
						if ( isset( $matches[ $i ] ) && '' !== $matches[ $i ] ) {
							$value = $matches[ $i ];
							break;
						}
					}
					if ( in_array( $name, self::uri_attributes(), true ) && ! self::url_is_safe( $value, self::default_protocols() ) ) {
						$bad_uri = true;
					}
				}

				if ( ! $bad_name && ! $bad_uri ) {
					$results[] = self::pass( $seed, $case_index, 'wp_kses_one_attr.event-and-uri-attribute-filtering', $piece, $details );
				} else {
					$results[] = self::fail(
						$seed,
						$case_index,
						'wp_kses_one_attr.event-and-uri-attribute-filtering',
						$piece,
						'no event handler attributes or forbidden URI protocols',
						array(
							'output'  => self::preview( $output ),
							'badName' => $bad_name,
							'badUri'  => $bad_uri,
						),
						$details
					);
				}
			} catch ( \Throwable $e ) {
				$results[] = self::throwable_result( $seed, $case_index, 'wp_kses_one_attr.no-throw', $piece, $e, self::case_details( $case ) );
			}
		}

		return $results;
	}

	private static function generate_case( array &$rng, int $case_index ): array {
		$corners = self::corner_cases();
		if ( isset( $corners[ $case_index ] ) ) {
			$case = $corners[ $case_index ];
			if ( ! isset( $case['protocols'] ) ) {
				$case['protocols'] = self::random_protocols( $rng );
			}
			return self::finalize_case( $case, $case_index );
		}

		$profile = self::rng_weighted(
			$rng,
			array(
				'protocol-attributes' => 18,
				'css-url-values'      => 14,
				'foreign-content'     => 12,
				'malformed-markup'    => 14,
				'nested-tags'         => 16,
				'comment-entities'    => 10,
				'attribute-boundaries' => 10,
				'allowed-matrix'      => 10,
				'byte-edges'          => 8,
			)
		);

		$protocols = self::random_protocols( $rng );
		$urls      = array(
			self::random_url( $rng ),
			self::random_url( $rng ),
			self::random_url( $rng ),
		);
		$attrs     = self::random_attr_list( $rng, $urls );
		$css       = self::random_css( $rng, $urls );
		$tag       = self::rng_choice( $rng, array( 'a', 'div', 'span', 'p', 'img', 'svg', 'math', 'form' ) );

		switch ( $profile ) {
			case 'foreign-content':
				$html = '<svg ' . $attrs . '><a xlink:href="' . $urls[0] . '"><circle onload="evil()" fill="url(' . $urls[1] . ')" /></a><foreignObject><p style="' . $css . '">text</p></foreignObject></svg>';
				$html .= '<math><mtext><a href="' . $urls[2] . '" onclick="evil()">math link</a></mtext></math>';
				break;
			case 'css-url-values':
				$html = '<div style="' . $css . '" data-info="ok"><a href="' . $urls[0] . '" style="background-image:url(' . $urls[1] . ');color:red">x</a></div>';
				break;
			case 'malformed-markup':
				$html = '<p ' . $attrs . '><<script>alert(1)</script><a href=' . $urls[0] . ' title="unterminated><img src="' . $urls[1] . '" onerror="evil()">';
				break;
			case 'nested-tags':
				$html = self::random_nodes( $rng, 4, $urls, $css );
				break;
			case 'comment-entities':
				$html = '<!--<img src=x onerror=evil()>--><!bogus><p title="&lt;safe&gt; &amp; &quot;quoted&quot;">'
					. self::random_text( $rng )
					. '</p><a href="' . self::rng_choice( $rng, $urls ) . '">entity &#x3c; text</a><!--dash---tail-->';
				break;
			case 'attribute-boundaries':
				$html = '<a href="' . $urls[0] . '" title="quote &quot; apostrophe &#039; gt &gt;" data-safe="x>y" onclick="evil()">'
					. 'boundary</a><p ' . $attrs . ' data-good="&lt;not-tag&gt;">attr text</p>'
					. '<img src="' . $urls[1] . '" alt="bad`tick" onerror="evil()">';
				break;
			case 'allowed-matrix':
				$html = self::allowed_matrix_html( $urls, $css );
				break;
			case 'byte-edges':
				$html = "\x00\x01" . '<div ' . $attrs . '>bytes ' . self::random_text( $rng ) . "\x0B\x0C" . '<a href="' . $urls[0] . '">link</a></div>';
				break;
			case 'protocol-attributes':
			default:
				$html = '<' . $tag . ' ' . $attrs . ' style="' . $css . '">payload ' . self::random_text( $rng ) . '</' . $tag . '>';
				$html .= '<a href="' . $urls[0] . '" onclick="evil()" data-url="' . $urls[1] . '">nested</a>';
				break;
		}

		return self::finalize_case(
			array(
				'profile'   => $profile,
				'html'      => $html,
				'urls'      => $urls,
				'css'       => $css,
				'attrs'     => $attrs,
				'tag'       => $tag,
				'protocols' => $protocols,
			),
			$case_index
		);
	}

	private static function corner_cases(): array {
		return array(
			array(
				'profile' => 'classic-xss',
				'html'    => '<a href="javascript:alert(1)" onclick="evil()" style="background-image:url(javascript:alert(1));color:red">click</a><img src=x onerror=alert(1)>',
				'urls'    => array( 'javascript:alert(1)', 'https://example.com/a?b=1&c[]=2', '/relative/path?x[]=1' ),
				'css'     => 'background-image:url(javascript:alert(1));color:red;behavior:url(#x);width:expression(alert(1))',
				'attrs'   => 'href="javascript:alert(1)" onclick="evil()" style="background:url(javascript:alert(1));color:red" data-ok="1"',
				'tag'     => 'a',
			),
			array(
				'profile' => 'entity-protocols',
				'html'    => '<a href="jav&#x09;ascript&#58;alert(1)" title="&lt;ok&gt;">entity</a><blockquote cite="feed:javascript:alert(1)">q</blockquote>',
				'urls'    => array( 'jav&#x09;ascript&#58;alert(1)', 'feed:javascript:alert(1)', 'http&#58;//example.org/' ),
				'css'     => 'background:url(&#106;&#97;vascript:alert(1));margin-top:2px;Text-transform:uppercase',
				'attrs'   => 'href="jav&#x09;ascript&#58;alert(1)" cite="feed:javascript:alert(1)" title="&lt;Hello&gt; &amp; &quot;World&quot;"',
				'tag'     => 'a',
			),
			array(
				'profile' => 'foreign-svg-math',
				'html'    => '<svg><a xlink:href="javascript:alert(1)"><circle onload="evil()" fill="red" /></a></svg><math><mtext><a href="data:text/html,<b>x</b>">math</a></mtext></math>',
				'urls'    => array( 'data:text/html,<svg/onload=alert(1)>', 'vbscript:msgbox(1)', '//example.com/path?x[y]=1' ),
				'css'     => 'fill:red;stroke-width:2;marker:url(javascript:alert(1));background-image:linear-gradient(red, blue)',
				'attrs'   => 'xlink:href="javascript:alert(1)" xml:space="preserve" onload="evil()" fill="red"',
				'tag'     => 'svg',
			),
			array(
				'profile' => 'css-custom-properties',
				'html'    => '<div style="--ok:10px;--bad:url(javascript:alert(1));background-image:url(https://example.com/bg.png);width:calc(100% - 1em)">css</div>',
				'urls'    => array( 'https://example.com/bg.png', 'javascript:alert(1)', 'mailto:?body=Hi%20there%0Aok' ),
				'css'     => '--ok:10px;--bad:url(javascript:alert(1));background-image:url(https://example.com/bg.png);width:calc(100% - 1em);-moz-binding:url(x)',
				'attrs'   => 'style="--bad:url(javascript:alert(1));background-image:url(https://example.com/bg.png);width:calc(100% - 1em)" class="has-style"',
				'tag'     => 'div',
			),
			array(
				'profile' => 'byte-and-null',
				'html'    => "\x00\x01<div title=\"bad\x0Bthing\" data-x=\"\\0\">Null \\0 slash zero &amp; &#x3c;script&#x3e;</div>\x0E",
				'urls'    => array( "\x00javascript:alert(1)", "java\x0Bscript:alert(1)", 'http://example.com/%0%0%0DAD' ),
				'css'     => "color:red;\x00background-image:url(javascript:alert(1));margin-top:\x0B2px",
				'attrs'   => "title=\"bad\x0Bthing\" href=\"\x00javascript:alert(1)\" data-x=\"\\0\"",
				'tag'     => 'a',
			),
			array(
				'profile' => 'malformed-comments',
				'html'    => '<!--<img src=x onerror=1>--><!script><p <<script>alert</script><a href=java' . "\t" . 'script:alert(1) title="x>y" data-x="<b>">bad',
				'urls'    => array( "java\t" . 'script:alert(1)', 'example.com?foo[bar]=baz', '?query[bad]=1' ),
				'css'     => 'font-weight:bold;foo:bar;filter:url(javascript:alert(1));background:green url("foo.jpg") no-repeat fixed center',
				'attrs'   => 'href=java' . "\t" . 'script:alert(1) title="x>y" data-x="<b>" disabled',
				'tag'     => 'a',
			),
			array(
				'profile' => 'required-and-data-wildcard',
				'html'    => '<p data-safe="1" data-evil.dot="2" aria-label="ok" onclick="evil()"><span data-name="v">text</span></p>',
				'urls'    => array( '#fragment', 'ftp://example.com/file.txt', 'foo://example.com/custom' ),
				'css'     => 'margin:10px 20px;padding:5px 10px;list-style-image:url(javascript:alert(1));white-space:pre-wrap',
				'attrs'   => 'data-safe="1" data-evil.dot="2" aria-label="ok" onclick="evil()"',
				'tag'     => 'p',
			),
			array(
				'profile' => 'srcset-and-media',
				'html'    => '<video poster="javascript:alert(1)"><source src="https://example.com/v.mp4" onerror="evil()"></video><img srcset="javascript:alert(1) 1x, https://example.com/a.png 2x" src="data:image/svg+xml,x">',
				'urls'    => array( 'https://example.com/v.mp4', 'data:image/svg+xml,<svg></svg>', 'feed:feed:javascript:alert(1)' ),
				'css'     => 'object-fit:cover;aspect-ratio:16/9;background-repeat:no-repeat;cursor:url(javascript:alert(1)), auto',
				'attrs'   => 'poster="javascript:alert(1)" src="https://example.com/v.mp4" srcset="javascript:alert(1) 1x, https://example.com/a.png 2x"',
				'tag'     => 'img',
			),
		);
	}

	private static function finalize_case( array $case, int $case_index ): array {
		$marker = 'cf-kses-marker-' . $case_index . '-' . substr( sha1( $case['profile'] . ':' . $case_index ), 0, 8 );

		$case['html'] .= '<p class="cf-marker" data-marker="' . $marker . '">' . $marker . '</p>';
		$case['textMarkers'] = array_values(
			array_unique(
				array_merge(
					$case['textMarkers'] ?? array(),
					array( $marker )
				)
			)
		);
		$case['markerAttr'] = 'data-marker="' . $marker . '"';

		return $case;
	}

	private static function allowed_matrix_html( array $urls, string $css ): string {
		return '<p class="kept" title="allowed" data-safe="1" data-bad.dot="drop" onclick="evil()">Paragraph text</p>'
			. '<span class="kept" data-name="value" aria-label="drop">Span text</span>'
			. '<a href="' . $urls[0] . '" title="allowed" rel="nofollow" data-safe="1" style="' . $css . '" onmouseover="evil()">Anchor text</a>'
			. '<strong>Strong text</strong><em>Em text</em><code>Code text</code>'
			. '<img src="' . $urls[1] . '" alt="drop"><script>script text</script><iframe src="' . $urls[2] . '">frame text</iframe>';
	}

	private static function random_nodes( array &$rng, int $depth, array $urls, string $css ): string {
		if ( $depth <= 0 ) {
			return self::random_text( $rng );
		}

		$out   = '';
		$count = self::rng_int( $rng, 2, 5 );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( self::rng_chance( $rng, 25 ) ) {
				$out .= self::random_text( $rng );
				continue;
			}

			if ( self::rng_chance( $rng, 18 ) ) {
				$out .= self::rng_choice(
					$rng,
					array(
						'<!--<script>alert(1)</script>-->',
						'<!bogus declaration>',
						'</3 invalid close>',
						'<!--dash---tail-->',
					)
				);
				continue;
			}

			$tag   = self::rng_choice( $rng, array( 'a', 'div', 'span', 'p', 'strong', 'em', 'code', 'blockquote', 'ul', 'li', 'table', 'tr', 'td', 'script', 'style', 'iframe', 'template', 'object', 'custom-element' ) );
			$attrs = self::random_attr_list( $rng, $urls );
			if ( in_array( $tag, array( 'script', 'style', 'iframe' ), true ) ) {
				$out .= '<' . $tag . ' ' . $attrs . '>alert(1)</' . $tag . '>';
			} elseif ( 'a' === $tag ) {
				$out .= '<a href="' . self::rng_choice( $rng, $urls ) . '" ' . $attrs . '>' . self::random_nodes( $rng, $depth - 1, $urls, $css ) . '</a>';
			} else {
				$out .= '<' . $tag . ' ' . $attrs . ' style="' . $css . '">' . self::random_nodes( $rng, $depth - 1, $urls, $css ) . '</' . $tag . '>';
			}
		}

		return $out;
	}

	private static function random_attr_list( array &$rng, array $urls ): string {
		$names = array(
			'href',
			'src',
			'srcset',
			'poster',
			'cite',
			'background',
			'action',
			'formaction',
			'longdesc',
			'usemap',
			'title',
			'alt',
			'class',
			'id',
			'style',
			'onclick',
			'onload',
			'onERROR',
			'onmouseover',
			'data-safe',
			'data--odd',
			'data-evil.dot',
			'aria-label',
			'aria-description',
			'xlink:href',
			'xml:space',
			'disabled',
			'[shortcode]',
		);

		$parts = array();
		$count = self::rng_int( $rng, 3, 8 );
		for ( $i = 0; $i < $count; ++$i ) {
			$name = self::rng_choice( $rng, $names );
			if ( in_array( $name, array( 'disabled', '[shortcode]' ), true ) && self::rng_chance( $rng, 55 ) ) {
				$parts[] = $name;
				continue;
			}

			if ( 'srcset' === strtolower( $name ) ) {
				$value = self::rng_choice( $rng, $urls ) . ' 1x, https://example.com/safe.png 2x';
			} elseif ( in_array( strtolower( $name ), self::uri_attributes(), true ) || 'xlink:href' === strtolower( $name ) ) {
				$value = self::rng_choice( $rng, $urls );
			} elseif ( 'style' === $name ) {
				$value = self::random_css( $rng, $urls );
			} else {
				$value = self::random_attr_value( $rng );
			}

			$quote = 'style' === strtolower( $name ) ? self::rng_choice( $rng, array( '"', "'" ) ) : self::rng_choice( $rng, array( '"', "'", '' ) );
			if ( '' === $quote ) {
				$value   = preg_replace( '/\s+/', '', $value );
				$parts[] = $name . '=' . $value;
			} else {
				$parts[] = $name . '=' . $quote . str_replace( $quote, '', $value ) . $quote;
			}
		}

		return implode( ' ', $parts );
	}

	private static function random_attr_value( array &$rng ): string {
		return self::rng_choice(
			$rng,
			array(
				'ok',
				'wide alignleft',
				'&lt;Hello&gt; &amp; &quot;World&quot;',
				'&#60;test&#62;',
				'bad"value',
				"bad'value",
				"x>y",
				"\x00nul\x0Bcontrol",
				"\xC3\x28invalid-utf8",
				'\\0 slash-zero',
			)
		);
	}

	private static function random_css( array &$rng, array $urls ): string {
		$allowed_props    = array_keys( self::css_allowed_properties() );
		$disallowed_props = array( 'behavior', '-moz-binding', 'foo', 'Text-transform', 'list-style-image' );
		$items            = array();
		$count            = self::rng_int( $rng, 4, 9 );

		for ( $i = 0; $i < $count; ++$i ) {
			if ( self::rng_chance( $rng, 28 ) ) {
				$prop = self::rng_choice( $rng, $disallowed_props );
			} else {
				$prop = self::rng_choice( $rng, $allowed_props );
			}

			if ( self::rng_chance( $rng, 22 ) ) {
				$prop = '--cf-' . self::rng_int( $rng, 1, 20 );
			}

			if ( in_array( strtolower( $prop ), self::css_url_properties(), true ) || self::rng_chance( $rng, 18 ) ) {
				$value = 'url(' . self::rng_choice( $rng, $urls ) . ')';
			} else {
				$value = self::rng_choice(
					$rng,
					array(
						'red',
						'2px',
						'10px 20px',
						'bold',
						'uppercase',
						'none',
						'block',
						'pre-wrap',
						'calc(100% - 1em)',
						'var(--wp--preset--spacing--20)',
						'repeat(2, minmax(0, 1fr))',
						'linear-gradient(red, blue)',
						'url("https://example.com/safe.png")',
						'image-set(url(https://example.com/a.png) 1x, url(javascript:alert(1)) 2x)',
						'expression(alert(1))',
						'2px}',
						'\\2px',
						'calc(var(--gap, 1rem) + 2px)',
					)
				);
			}

			$items[] = $prop . ':' . $value;
		}

		$items[] = 'background-image:url(javascript:alert(1))';
		$items[] = 'color:red';

		return implode( ';', $items );
	}

	private static function random_url( array &$rng ): string {
		return self::rng_choice(
			$rng,
			array(
				'http://example.com/path?x=1&y[]=2',
				'https://example.org/a%20b?one=1&two=2#frag',
				'mailto:?body=Hi%20there%0Aok',
				'ftp://example.com/file.txt',
				'//example.com/protocol-relative?foo[bar]=baz',
				'/relative/path?x[]=1',
				'#fragment',
				'?query[bad]=1',
				'example.com/bare?x[y]=1',
				'javascript:alert(1)',
				'JaVaScRiPt:alert(1)',
				"java\t" . 'script:alert(1)',
				"java\r\nscript:alert(1)",
				'jav&#x09;ascript&#58;alert(1)',
				'&#x6a;&#x61;vascript:alert(1)',
				'javascript&#0000058alert(1)',
				'javascript%3Aalert(1)',
				'java%0Ascript:alert(1)',
				'%6a%61vascript:alert(1)',
				'feed:javascript:alert(1)',
				'feed:feed:javascript:alert(1)',
				'data:text/html,<svg/onload=alert(1)>',
				'data:image/svg+xml,%3Csvg%20onload=alert(1)%3E',
				'vbscript:msgbox(1)',
				'file:///etc/passwd',
				'blob:https://example.com/uuid',
				"\x00javascript:alert(1)",
				'http://[::FFFF::127.0.0.1]/?foo[bar]=baz',
				'https://example.com/%3Cscript%3E?x=%26y%3D1',
				'mailto:user@example.com?subject=%3Ctag%3E&body=Hi%0Athere',
				'//user:pass@example.com/%2f%2e%2e',
				'foo://example.com/custom',
			)
		);
	}

	private static function random_protocols( array &$rng ): array {
		$sets = array(
			self::default_protocols(),
			array( 'http', 'https' ),
			array( 'https', 'http' ),
			array( 'http', 'https', 'mailto' ),
			array( 'http', 'https', 'ftp', 'mailto', 'feed' ),
			array( 'foo' ),
		);

		return self::rng_choice( $rng, $sets );
	}

	private static function random_text( array &$rng ): string {
		return self::rng_choice(
			$rng,
			array(
				'plain text',
				'AT&T & not-an-entity &bogus;',
				'encoded &#x3c;script&#x3e; text',
				"\x00nul\x01start\x0Bvertical-tab",
				"\xC3\x28invalid utf8",
				"\xE2\x98\x83 snowman",
				'quotes "\' and < >',
				'\\0 slash-zero sequence',
			)
		);
	}

	private static function strict_policy(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'data-*' => true,
			),
			'br'     => array(),
			'code'   => array(),
			'em'     => array(),
			'p'      => array(
				'class'  => true,
				'title'  => true,
				'data-*' => true,
			),
			'span'   => array(
				'class'  => true,
				'title'  => true,
				'data-*' => true,
			),
			'strong' => array(),
		);
	}

	private static function custom_context_policy(): array {
		return array(
			'a'    => array(
				'href'    => true,
				'data-cf' => true,
			),
			'mark' => array(
				'data-cf'       => true,
				'data-cf-extra' => true,
			),
		);
	}

	private static function post_output_security_violations( string $html, array $protocols ): array {
		$allowed = function_exists( 'wp_kses_allowed_html' ) ? \wp_kses_allowed_html( 'post' ) : array();
		$policy_violations = is_array( $allowed ) ? self::policy_violations( $html, $allowed, $protocols, false ) : array();

		return $policy_violations;
	}

	private static function style_attribute_violations( string $html, array $protocols ): array {
		$violations = array();
		$styles     = self::style_attribute_values( $html );

		foreach ( $styles as $style ) {
			foreach ( self::css_policy_violations( self::decode_attribute_text( $style['value'] ), $protocols ) as $violation ) {
				$violations[] = array(
					'type'      => 'style-attribute',
					'attribute' => 'style',
					'offset'    => $style['offset'],
					'violation' => $violation,
				);
			}
		}

		return $violations;
	}

	private static function style_attribute_values( string $html ): array {
		$values = array();
		if ( 1 !== preg_match_all( '/<\s*[A-Za-z][A-Za-z0-9:-]*\b([^<>]*)>/s', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $values;
		}

		foreach ( $tags as $tag ) {
			$attr_text = $tag[1][0];
			$attr_base = $tag[1][1];
			if ( 1 === preg_match_all( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $attr_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$values[] = array(
						'value'  => $match[2][0],
						'offset' => $attr_base + $match[0][1],
					);
				}
			}

			if ( 1 === preg_match_all( '/\sstyle\s*=\s*([^\s"\'=<>`]+)/i', $attr_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$values[] = array(
						'value'  => $match[1][0],
						'offset' => $attr_base + $match[0][1],
					);
				}
			}
		}

		return $values;
	}

	private static function attribute_boundary_violations( string $html ): array {
		$violations = array();
		if ( 1 !== preg_match_all( '/<\s*[A-Za-z][A-Za-z0-9:-]*\b([^<>]*)>/s', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $violations;
		}

		foreach ( $tags as $tag ) {
			$attr_text = $tag[1][0];
			$attr_base = $tag[1][1];
			if ( 1 === preg_match_all( '/\s([A-Za-z_:][A-Za-z0-9_:\.-]*)\s*=\s*(["\'])(.*?)\2/s', $attr_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$value = $match[3][0];
					if ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
						$violations[] = array(
							'type'      => 'raw-angle-in-quoted-attribute',
							'attribute' => $match[1][0],
							'value'     => self::preview( $value ),
							'offset'    => $attr_base + $match[0][1],
						);
					}
				}
			}

			if ( 1 === preg_match_all( '/\s([A-Za-z_:][A-Za-z0-9_:\.-]*)\s*=\s*`/s', $attr_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$violations[] = array(
						'type'      => 'backtick-quoted-attribute',
						'attribute' => $match[1][0],
						'offset'    => $attr_base + $match[0][1],
					);
				}
			}
		}

		return $violations;
	}

	private static function comment_syntax_violations( string $html ): array {
		$violations = array();
		$open_count  = substr_count( $html, '<!--' );
		$close_count = substr_count( $html, '-->' );
		if ( $open_count !== $close_count ) {
			$violations[] = array(
				'type'   => 'unbalanced-comment-boundary',
				'opens'  => $open_count,
				'closes' => $close_count,
			);
		}

		if ( 1 === preg_match_all( '/<!--(.*?)-->/s', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$content = $match[1][0];
				if ( false !== strpos( $content, '--' ) || str_ends_with( $content, '-' ) ) {
					$violations[] = array(
						'type'    => 'unsafe-comment-dash-sequence',
						'content' => self::preview( $content ),
						'offset'  => $match[0][1],
					);
				}
			}
		}

		return $violations;
	}

	private static function policy_violations( string $html, array $allowed_html, array $protocols, bool $tag_must_be_allowed = true ): array {
		$violations = self::policy_violations_with_tag_processor( $html, $allowed_html, $protocols, $tag_must_be_allowed );
		if ( null !== $violations ) {
			return $violations;
		}

		return self::policy_violations_with_regex( $html, $allowed_html, $protocols, $tag_must_be_allowed );
	}

	private static function policy_violations_with_tag_processor( string $html, array $allowed_html, array $protocols, bool $tag_must_be_allowed ): ?array {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return null;
		}

		try {
			$violations = array();
			$processor  = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag() ) {
				$tag = strtolower( $processor->get_tag() );
				if ( $tag_must_be_allowed && ! isset( $allowed_html[ $tag ] ) ) {
					$violations[] = array(
						'type' => 'tag',
						'tag'  => $tag,
					);
					continue;
				}

				if ( ! method_exists( $processor, 'get_attribute_names_with_prefix' ) ) {
					continue;
				}

				$names = $processor->get_attribute_names_with_prefix( '' );
				if ( null === $names ) {
					continue;
				}

				foreach ( $names as $name ) {
					$lower = strtolower( $name );
					if ( $tag_must_be_allowed && ! self::attribute_allowed_for_tag( $lower, $allowed_html[ $tag ] ?? array() ) ) {
						$violations[] = array(
							'type'      => 'attribute',
							'tag'       => $tag,
							'attribute' => $name,
						);
					}

					if ( 0 === strpos( $lower, 'on' ) ) {
						$violations[] = array(
							'type'      => 'event-attribute',
							'tag'       => $tag,
							'attribute' => $name,
						);
					}

					$value = $processor->get_attribute( $name );
					if ( is_string( $value ) && in_array( $lower, self::uri_attributes(), true ) && ! self::url_is_safe( $value, $protocols ) ) {
						$violations[] = array(
							'type'      => 'uri-protocol',
							'tag'       => $tag,
							'attribute' => $name,
							'value'     => self::preview( $value ),
							'scheme'    => self::leading_scheme( $value ),
						);
					}
				}
			}

			return $violations;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function policy_violations_with_regex( string $html, array $allowed_html, array $protocols, bool $tag_must_be_allowed ): array {
		$violations = array();
		if ( 1 !== preg_match_all( '/<\s*([A-Za-z0-9-]+)([^>]*)>/s', $html, $matches, PREG_SET_ORDER ) ) {
			return $violations;
		}

		foreach ( $matches as $match ) {
			$tag      = strtolower( $match[1] );
			$attrtext = $match[2];
			if ( $tag_must_be_allowed && ! isset( $allowed_html[ $tag ] ) ) {
				$violations[] = array(
					'type' => 'tag',
					'tag'  => $tag,
				);
				continue;
			}

			if ( 1 !== preg_match_all( '/([A-Za-z_:][A-Za-z0-9_:\.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/s', $attrtext, $attrs, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $attrs as $attr ) {
				$name  = strtolower( $attr[1] );
				$value = '';
				for ( $i = 2; $i <= 4; ++$i ) {
					if ( isset( $attr[ $i ] ) && '' !== $attr[ $i ] ) {
						$value = $attr[ $i ];
						break;
					}
				}

				if ( $tag_must_be_allowed && ! self::attribute_allowed_for_tag( $name, $allowed_html[ $tag ] ?? array() ) ) {
					$violations[] = array(
						'type'      => 'attribute',
						'tag'       => $tag,
						'attribute' => $name,
					);
				}
				if ( 0 === strpos( $name, 'on' ) ) {
					$violations[] = array(
						'type'      => 'event-attribute',
						'tag'       => $tag,
						'attribute' => $name,
					);
				}
				if ( in_array( $name, self::uri_attributes(), true ) && ! self::url_is_safe( $value, $protocols ) ) {
					$violations[] = array(
						'type'      => 'uri-protocol',
						'tag'       => $tag,
						'attribute' => $name,
						'value'     => self::preview( $value ),
						'scheme'    => self::leading_scheme( $value ),
					);
				}
			}
		}

		return $violations;
	}

	private static function attribute_allowed_for_tag( string $name, $allowed_attrs ): bool {
		if ( true === $allowed_attrs ) {
			return true;
		}
		if ( ! is_array( $allowed_attrs ) ) {
			return false;
		}
		if ( array_key_exists( $name, $allowed_attrs ) && false !== $allowed_attrs[ $name ] && '' !== $allowed_attrs[ $name ] ) {
			return true;
		}
		if ( 0 === strpos( $name, 'data-' ) && ! empty( $allowed_attrs['data-*'] ) && 1 === preg_match( '/^data-[a-z0-9_-]+$/', $name ) ) {
			return true;
		}

		return false;
	}

	private static function hair_violations( array $hair, array $protocols ): array {
		$violations = array();
		foreach ( $hair as $key => $record ) {
			if ( ! is_array( $record ) ) {
				$violations[] = array(
					'type' => 'record-shape',
					'key'  => $key,
				);
				continue;
			}

			foreach ( array( 'name', 'value', 'whole', 'vless' ) as $field ) {
				if ( ! array_key_exists( $field, $record ) || ! is_string( $record[ $field ] ) ) {
					$violations[] = array(
						'type'  => 'record-field',
						'key'   => $key,
						'field' => $field,
					);
				}
			}

			$name = strtolower( (string) ( $record['name'] ?? '' ) );
			if ( in_array( $name, self::uri_attributes(), true ) && ! self::url_is_safe( (string) ( $record['value'] ?? '' ), $protocols ) ) {
				$violations[] = array(
					'type'      => 'uri-protocol',
					'attribute' => $name,
					'value'     => self::preview( (string) ( $record['value'] ?? '' ) ),
					'scheme'    => self::leading_scheme( (string) ( $record['value'] ?? '' ) ),
				);
			}

		}

		return $violations;
	}

	private static function css_policy_violations( string $css, array $protocols ): array {
		$violations       = array();
		$allowed_props    = self::css_allowed_properties();
		$disallowed_props = array_fill_keys( array( 'behavior', '-moz-binding', 'foo', 'text-transform-case-probe', 'list-style-image' ), true );

		foreach ( explode( ';', $css ) as $item ) {
			$item = trim( $item );
			if ( '' === $item || false === strpos( $item, ':' ) ) {
				continue;
			}

			$parts = explode( ':', $item, 2 );
			$prop  = trim( $parts[0] );
			$value = trim( $parts[1] );
			$lower = strtolower( $prop );

			if ( isset( $disallowed_props[ $lower ] ) && ! isset( $allowed_props[ $prop ] ) ) {
				$violations[] = array(
					'type'     => 'disallowed-property',
					'property' => $prop,
				);
			}

			$is_custom_property = isset( $allowed_props['--*'] ) && 1 === preg_match( '/^--[A-Za-z0-9-_]+$/', $prop );
			if ( ! $is_custom_property && ! isset( $allowed_props[ $prop ] ) ) {
				$violations[] = array(
					'type'     => 'unknown-property',
					'property' => $prop,
				);
			}

			if ( 1 === preg_match_all( '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)/i', $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$url = '';
					for ( $i = 1; $i <= 3; ++$i ) {
						if ( isset( $match[ $i ] ) && '' !== $match[ $i ] ) {
							$url = trim( $match[ $i ] );
							break;
						}
					}
					if ( '' !== $url && ! self::url_is_safe( $url, $protocols ) ) {
						$violations[] = array(
							'type'     => 'url-protocol',
							'property' => $prop,
							'value'    => self::preview( $url ),
							'scheme'   => self::leading_scheme( $url ),
						);
					}
				}
			}
		}

		if ( false !== strpos( $css, '/*' ) || false !== strpos( $css, '}' ) || false !== strpos( $css, '\\' ) ) {
			$violations[] = array(
				'type' => 'unsafe-css-token',
			);
		}

		return $violations;
	}

	private static function css_allowed_properties(): array {
		$props = self::base_css_allowed_properties();
		if ( ! function_exists( 'apply_filters' ) ) {
			return $props;
		}

		$filtered = \apply_filters( 'safe_style_css', array_keys( $props ) );
		if ( ! is_array( $filtered ) || empty( $filtered ) ) {
			return $props;
		}

		$props = array();
		foreach ( $filtered as $property ) {
			$property = (string) $property;
			if ( '' !== $property ) {
				$props[ $property ] = true;
			}
		}

		return $props;
	}

	private static function base_css_allowed_properties(): array {
		static $props = null;
		if ( null !== $props ) {
			return $props;
		}

		$names = array(
			'background',
			'background-color',
			'background-image',
			'background-position',
			'background-repeat',
			'background-size',
			'background-attachment',
			'background-blend-mode',
			'border',
			'border-radius',
			'border-width',
			'border-color',
			'border-style',
			'border-right',
			'border-right-color',
			'border-right-style',
			'border-right-width',
			'border-bottom',
			'border-bottom-color',
			'border-bottom-left-radius',
			'border-bottom-right-radius',
			'border-bottom-style',
			'border-bottom-width',
			'border-left',
			'border-left-color',
			'border-left-style',
			'border-left-width',
			'border-top',
			'border-top-color',
			'border-top-left-radius',
			'border-top-right-radius',
			'border-top-style',
			'border-top-width',
			'border-spacing',
			'border-collapse',
			'caption-side',
			'columns',
			'column-count',
			'column-fill',
			'column-gap',
			'column-rule',
			'column-span',
			'column-width',
			'display',
			'color',
			'filter',
			'font',
			'font-family',
			'font-size',
			'font-style',
			'font-variant',
			'font-weight',
			'letter-spacing',
			'line-height',
			'text-align',
			'text-decoration',
			'text-indent',
			'text-transform',
			'white-space',
			'height',
			'min-height',
			'max-height',
			'width',
			'min-width',
			'max-width',
			'margin',
			'margin-right',
			'margin-bottom',
			'margin-left',
			'margin-top',
			'margin-block-start',
			'margin-block-end',
			'margin-inline-start',
			'margin-inline-end',
			'padding',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'padding-top',
			'padding-block-start',
			'padding-block-end',
			'padding-inline-start',
			'padding-inline-end',
			'flex',
			'flex-basis',
			'flex-direction',
			'flex-flow',
			'flex-grow',
			'flex-shrink',
			'flex-wrap',
			'gap',
			'row-gap',
			'grid-template-columns',
			'grid-auto-columns',
			'grid-column-start',
			'grid-column-end',
			'grid-column',
			'grid-column-gap',
			'grid-template-rows',
			'grid-auto-rows',
			'grid-row-start',
			'grid-row-end',
			'grid-row',
			'grid-row-gap',
			'grid-gap',
			'justify-content',
			'justify-items',
			'justify-self',
			'align-content',
			'align-items',
			'align-self',
			'clear',
			'cursor',
			'direction',
			'float',
			'list-style-type',
			'object-fit',
			'object-position',
			'opacity',
			'overflow',
			'vertical-align',
			'writing-mode',
			'position',
			'top',
			'right',
			'bottom',
			'left',
			'z-index',
			'box-shadow',
			'aspect-ratio',
			'container-type',
			'fill',
			'fill-opacity',
			'fill-rule',
			'stroke',
			'stroke-dasharray',
			'stroke-dashoffset',
			'stroke-linecap',
			'stroke-linejoin',
			'stroke-miterlimit',
			'stroke-opacity',
			'stroke-width',
			'color-interpolation',
			'color-interpolation-filters',
			'paint-order',
			'stop-color',
			'stop-opacity',
			'flood-color',
			'flood-opacity',
			'lighting-color',
			'marker',
			'marker-end',
			'marker-mid',
			'marker-start',
			'clip-path',
			'clip-rule',
			'mask',
			'mask-type',
			'cx',
			'cy',
			'r',
			'rx',
			'ry',
			'x',
			'y',
			'd',
			'alignment-baseline',
			'baseline-shift',
			'dominant-baseline',
			'glyph-orientation-horizontal',
			'glyph-orientation-vertical',
			'text-anchor',
			'unicode-bidi',
			'word-spacing',
			'font-size-adjust',
			'font-stretch',
			'color-rendering',
			'image-rendering',
			'shape-rendering',
			'text-rendering',
			'vector-effect',
			'transform',
			'transform-origin',
			'pointer-events',
			'visibility',
			'--*',
		);

		$props = array_fill_keys( $names, true );
		return $props;
	}

	private static function css_url_properties(): array {
		return array(
			'background',
			'background-image',
			'cursor',
			'filter',
			'list-style',
			'list-style-image',
		);
	}

	private static function interesting_attr_pieces( array $case ): array {
		$pieces = array(
			'href="' . $case['urls'][0] . '"',
			'onclick="evil()"',
			'style="' . $case['css'] . '"',
			'title="safe"',
		);

		if ( 1 === preg_match_all( '/(?:^|\s)((?:[^\s"\'=<>`]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)/', $case['attrs'], $matches ) ) {
			foreach ( array_slice( $matches[1], 0, 4 ) as $piece ) {
				$pieces[] = $piece;
			}
		}

		return array_values( array_unique( $pieces ) );
	}

	private static function block_attribute_allowed_html(): array {
		return array(
			'a'       => array(
				'href'  => true,
				'title' => true,
			),
			'div'     => array(
				'data-safe' => true,
			),
			'em'      => array(),
			'main'    => array(),
			'section' => array(),
			'span'    => array(
				'data-safe' => true,
				'style'     => true,
			),
			'strong'  => array(),
		);
	}

	private static function block_attribute_case( string $token ): array {
		return array(
			'blockName'    => 'core/group',
			'attrs'        => array(
				'anchor'                  => 'safe-anchor-' . $token,
				'linkHtml'                => '<a href="javascript:alert(1)" onclick="evil()">Bad</a>'
					. '<a href="https://example.test/' . $token . '" title="Safe">Good</a>',
				'nested'                  => array(
					'<script>bad</script>Key' => '<span style="color:red;background-image:url(javascript:alert(1))" data-safe="yes" onclick="evil()">Span</span>',
					'count'                   => 3,
					'enabled'                 => true,
					'empty'                   => null,
				),
				'entityText'              => 'Keep &amp; normalize &#x3c;strong&#x3e;text&#x3c;/strong&#x3e;',
				'xml:lang<script>x</script>' => 'en',
			),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'core/template-part',
					'attrs'        => array(
						'tagName' => 'script',
						'nested'  => array(
							'tagName' => 'main',
						),
						'label'   => '<strong>Header ' . $token . '</strong>',
					),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
			),
			'innerHTML'    => '',
			'innerContent' => array( null ),
		);
	}

	private static function block_kses_value_reference( $value, array $allowed_html, array $allowed_protocols, ?array $block_context = null ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner_value ) {
				$filtered_key   = self::block_kses_value_reference( $key, $allowed_html, $allowed_protocols, $block_context );
				$filtered_value = self::block_kses_value_reference( $inner_value, $allowed_html, $allowed_protocols, $block_context );

				if ( isset( $block_context['blockName'] ) && 'core/template-part' === $block_context['blockName'] ) {
					$filtered_value = self::block_template_part_attribute_reference( $filtered_value, $filtered_key, $allowed_html );
				}
				if ( $filtered_key !== $key ) {
					unset( $value[ $key ] );
				}

				$value[ $filtered_key ] = $filtered_value;
			}

			return $value;
		}

		if ( is_string( $value ) ) {
			return \wp_kses( $value, $allowed_html, $allowed_protocols );
		}

		return $value;
	}

	private static function block_template_part_attribute_reference( $attribute_value, string $attribute_name, array $allowed_html ) {
		if ( empty( $attribute_value ) || 'tagName' !== $attribute_name ) {
			return $attribute_value;
		}

		return isset( $allowed_html[ $attribute_value ] ) ? $attribute_value : '';
	}

	private static function missing_text_markers( string $html, array $case ): array {
		$missing = array();
		foreach ( $case['textMarkers'] ?? array() as $marker ) {
			if ( false === strpos( $html, $marker ) ) {
				$missing[] = $marker;
			}
		}

		return $missing;
	}

	private static function marker_attribute_expectation( array $case ): string {
		return (string) ( $case['markerAttr'] ?? '' );
	}

	private static function strict_marker_attribute_retained( string $html, array $case ): bool {
		$marker_attr = self::marker_attribute_expectation( $case );
		return '' === $marker_attr || false !== strpos( $html, $marker_attr );
	}

	private static function contains_data_url( string $value, string $data_url ): bool {
		return false !== stripos( self::decode_attribute_text( $value ), self::decode_attribute_text( $data_url ) );
	}

	private static function contains_decoded_text( string $value, string $needle ): bool {
		return false !== stripos( self::decode_attribute_text( $value ), self::decode_attribute_text( $needle ) );
	}

	private static function contains_protocol_marker( string $value, string $protocol ): bool {
		return self::contains_decoded_text( $value, $protocol . ':' );
	}

	private static function seen_semicolon_data_url_split( array $seen, string $data_url ): bool {
		$decoded_url       = self::decode_attribute_text( $data_url );
		$semicolon_offset  = strpos( $decoded_url, ';' );
		$comma_offset      = strpos( $decoded_url, ',' );
		$data_url_prefix   = false === $semicolon_offset ? $decoded_url : substr( $decoded_url, 0, $semicolon_offset );
		$data_url_metadata = false === $semicolon_offset ? '' : substr(
			$decoded_url,
			$semicolon_offset + 1,
			false === $comma_offset ? null : $comma_offset - $semicolon_offset - 1
		);
		$saw_prefix        = false;
		$saw_metadata      = '' === $data_url_metadata;

		foreach ( $seen as $hit ) {
			if ( ! is_array( $hit ) || ! isset( $hit['test'] ) ) {
				continue;
			}

			$test = self::decode_attribute_text( (string) $hit['test'] );
			if ( false !== stripos( $test, 'url(' . $data_url_prefix ) || false !== stripos( $test, 'url("' . $data_url_prefix ) || false !== stripos( $test, "url('" . $data_url_prefix ) ) {
				$saw_prefix = true;
			}
			if ( '' !== $data_url_metadata && false !== stripos( ltrim( $test ), $data_url_metadata . ',' ) ) {
				$saw_metadata = true;
			}
		}

		return $saw_prefix && $saw_metadata;
	}

	private static function looks_like_semicolon_data_url_css_fragment( string $css_test_string ): bool {
		$test = strtolower( trim( self::decode_attribute_text( $css_test_string ) ) );
		if ( false !== strpos( $test, 'url(data:' ) || false !== strpos( $test, 'url("data:' ) || false !== strpos( $test, "url('data:" ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^(?:base64|charset=[^,]+|profile=[^,]+|version=[^,]+),/i', $test ) ) {
			return true;
		}

		return false;
	}

	private static function looks_like_fragmented_bad_protocol_css_fragment( string $css_test_string ): bool {
		$test = strtolower( trim( self::decode_attribute_text( $css_test_string ) ) );
		foreach ( array( 'javascript', 'vbscript', 'livescript', 'mocha' ) as $protocol ) {
			$quoted_protocol = preg_quote( $protocol, '/' );
			if ( 1 === preg_match( '/url\(\s*[\'"]?' . $quoted_protocol . '\s*:/i', $test ) ) {
				return true;
			}
		}
		if ( 1 === preg_match( '/url\(\s*[\'"]?(?:java|vb|vbs|live|moc)$/i', $test ) ) {
			return true;
		}

		return false;
	}

	private static function decode_attribute_text( string $value ): string {
		if ( class_exists( '\WP_HTML_Decoder' ) && method_exists( '\WP_HTML_Decoder', 'decode_attribute' ) ) {
			try {
				return \WP_HTML_Decoder::decode_attribute( $value );
			} catch ( \Throwable $e ) {
				// Fall back to PHP's entity decoder below.
			}
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function case_filter_tag( int $seed, int $case_index, string $purpose ): string {
		return 'component_fuzz_kses_' . $purpose . '_' . substr( sha1( $seed . ':' . $case_index . ':' . $purpose ), 0, 12 );
	}

	private static function allowed_html_case_violations( array $allowed_html ): array {
		$violations = array();
		foreach ( $allowed_html as $tag => $attrs ) {
			if ( is_string( $tag ) && strtolower( $tag ) !== $tag ) {
				$violations[] = array(
					'type' => 'tag',
					'name' => $tag,
				);
			}
			if ( is_array( $attrs ) ) {
				foreach ( $attrs as $attr => $_value ) {
					if ( is_string( $attr ) && strtolower( $attr ) !== $attr ) {
						$violations[] = array(
							'type' => 'attribute',
							'tag'  => $tag,
							'name' => $attr,
						);
					}
				}
			}
		}

		return $violations;
	}

	private static function url_is_safe( string $url, array $protocols ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return true;
		}
		if ( '/' === $url[0] || '#' === $url[0] || '?' === $url[0] ) {
			return true;
		}

		$scheme = self::leading_scheme( $url );
		if ( null === $scheme ) {
			return true;
		}

		return in_array( $scheme, self::lowercase_list( $protocols ), true );
	}

	private static function leading_scheme( string $value ): ?string {
		$decoded = self::decode_protocol_text( $value );
		$decoded = preg_replace( '/[\x00-\x20]+/', '', $decoded );
		if ( 1 === preg_match( '/^([A-Za-z][A-Za-z0-9+.-]*):/', $decoded, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return null;
	}

	private static function decode_protocol_text( string $value ): string {
		$decoded = $value;
		for ( $i = 0; $i < 4; ++$i ) {
			$previous = $decoded;
			if ( function_exists( 'wp_kses_decode_entities' ) ) {
				$decoded = \wp_kses_decode_entities( $decoded );
			}
			$decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $previous ) {
				break;
			}
		}

		return $decoded;
	}

	private static function bad_protocol_fixed_point( string $content, array $allowed_protocols ): string {
		$content    = \wp_kses_no_null( $content );
		$iterations = 0;

		do {
			$original_content = $content;
			$content          = \wp_kses_bad_protocol_once( $content, $allowed_protocols );
		} while ( $original_content !== $content && ++$iterations < 6 );

		if ( $original_content !== $content ) {
			return '';
		}

		return $content;
	}

	private static function protocol_scheme_probes( array $case ): array {
		$schemes = array(
			'http',
			'HTTPS',
			'feed',
			'foo',
			'data',
			'jav&#x09;ascript',
			'&#x6a;&#x61;vascript',
			"java\t\nscript",
			"java\x00script",
			'\\0https',
		);

		foreach ( $case['urls'] as $url ) {
			if ( 1 === preg_match( '/^([^:]+):/', $url, $matches ) ) {
				$schemes[] = $matches[1];
			}
			if ( 1 === preg_match( '/^(.+?)(?:&#0*58;?|&#x0*3a;?|&colon;)/i', $url, $matches ) ) {
				$schemes[] = $matches[1];
			}
		}

		return array_values( array_unique( $schemes ) );
	}

	private static function bad_protocol_once2_expected( string $scheme, array $allowed_protocols ): string {
		$scheme = \wp_kses_decode_entities( $scheme );
		$scheme = preg_replace( '/\s/', '', $scheme );
		$scheme = \wp_kses_no_null( $scheme );
		$scheme = strtolower( $scheme );

		if ( in_array( $scheme, self::lowercase_list( $allowed_protocols ), true ) ) {
			return $scheme . ':';
		}

		return '';
	}

	private static function raw_control_violation( string $value ): ?array {
		if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'offset' => $matches[0][1],
				'ord'    => ord( $matches[0][0] ),
			);
		}

		return null;
	}

	private static function contains_html_like_tag( string $value ): bool {
		return 1 === preg_match( '/<\s*\/?\s*[A-Za-z][A-Za-z0-9:-]*(?:\s[^<>]*)?>/', $value );
	}

	private static function uri_attributes(): array {
		if ( function_exists( 'wp_kses_uri_attributes' ) ) {
			$attrs = \wp_kses_uri_attributes();
			if ( is_array( $attrs ) ) {
				return self::lowercase_list( $attrs );
			}
		}

		return self::FALLBACK_URI_ATTRIBUTES;
	}

	private static function default_protocols(): array {
		if ( function_exists( 'wp_allowed_protocols' ) ) {
			$protocols = \wp_allowed_protocols();
			if ( is_array( $protocols ) && ! empty( $protocols ) ) {
				return self::lowercase_list( $protocols );
			}
		}

		return array( 'http', 'https', 'ftp', 'mailto', 'feed' );
	}

	private static function lowercase_list( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			$out[] = strtolower( (string) $value );
		}

		return array_values( array_unique( $out ) );
	}

	private static function kses_filter_lifecycle_hooks(): array {
		return array(
			'title_save_pre',
			'pre_comment_content',
			'content_save_pre',
			'excerpt_save_pre',
			'content_filtered_save_pre',
		);
	}

	private static function kses_filter_lifecycle_callbacks(): array {
		return array(
			'wp_filter_kses',
			'wp_filter_post_kses',
			'wp_filter_global_styles_post',
		);
	}

	private static function kses_filter_lifecycle_state(): array {
		$state = array();
		foreach ( self::kses_filter_lifecycle_hooks() as $hook ) {
			$state[ $hook ] = array();
			foreach ( self::kses_filter_lifecycle_callbacks() as $callback ) {
				$priority = \has_filter( $hook, $callback );
				$state[ $hook ][ $callback ] = false === $priority ? false : (int) $priority;
			}
		}
		return $state;
	}

	private static function kses_filter_lifecycle_expected( string $mode ): array {
		$removed = array();
		foreach ( self::kses_filter_lifecycle_hooks() as $hook ) {
			$removed[ $hook ] = array_fill_keys( self::kses_filter_lifecycle_callbacks(), false );
		}

		if ( 'removed' === $mode ) {
			return $removed;
		}

		$expected = $removed;
		$expected['title_save_pre']['wp_filter_kses'] = 10;
		if ( 'granted' === $mode ) {
			$expected['pre_comment_content']['wp_filter_post_kses'] = 10;
		} else {
			$expected['pre_comment_content']['wp_filter_kses'] = 10;
		}
		$expected['content_save_pre']['wp_filter_global_styles_post']          = 9;
		$expected['content_save_pre']['wp_filter_post_kses']                  = 10;
		$expected['excerpt_save_pre']['wp_filter_post_kses']                  = 10;
		$expected['content_filtered_save_pre']['wp_filter_global_styles_post'] = 9;
		$expected['content_filtered_save_pre']['wp_filter_post_kses']          = 10;

		return $expected;
	}

	private static function kses_filter_lifecycle_failures( string $label, array $actual, array $expected ): array {
		$failures = array();
		foreach ( $expected as $hook => $callbacks ) {
			foreach ( $callbacks as $callback => $expected_priority ) {
				$actual_priority = $actual[ $hook ][ $callback ] ?? false;
				if ( $expected_priority !== $actual_priority ) {
					$failures[] = array(
						'label'    => $label,
						'hook'     => $hook,
						'callback' => $callback,
						'expected' => $expected_priority,
						'actual'   => $actual_priority,
					);
				}
			}
		}
		return $failures;
	}

	private static function snapshot_hooks( array $hook_names ): array {
		$snapshot = array();
		foreach ( $hook_names as $hook_name ) {
			$snapshot[ $hook_name ] = self::snapshot_hook( $hook_name );
		}
		return $snapshot;
	}

	private static function restore_hooks( array $snapshot ): void {
		foreach ( $snapshot as $hook_name => $hook_snapshot ) {
			self::restore_hook( $hook_name, $hook_snapshot );
		}
	}

	private static function snapshot_hook( string $hook_name ): array {
		$exists = isset( $GLOBALS['wp_filter'] )
			&& is_array( $GLOBALS['wp_filter'] )
			&& array_key_exists( $hook_name, $GLOBALS['wp_filter'] );

		if ( ! $exists ) {
			return array(
				'exists' => false,
				'value'  => null,
			);
		}

		$value = $GLOBALS['wp_filter'][ $hook_name ];
		return array(
			'exists' => true,
			'value'  => is_object( $value ) ? clone $value : $value,
		);
	}

	private static function restore_hook( string $hook_name, array $snapshot ): void {
		if ( empty( $snapshot['exists'] ) ) {
			if ( isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ) {
				unset( $GLOBALS['wp_filter'][ $hook_name ] );
			}
			return;
		}

		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			$GLOBALS['wp_filter'] = array();
		}

		$value = $snapshot['value'] ?? null;
		$GLOBALS['wp_filter'][ $hook_name ] = is_object( $value ) ? clone $value : $value;
	}

	private static function check_pre_kses_hook_state( int $seed, string $invariant, array $expected_signature, $expected_priority ): array {
		$actual_signature = self::hook_signature( 'pre_kses' );
		$actual_priority  = \has_filter( 'pre_kses', 'wp_pre_kses_block_attributes' );
		$priority_ok      = false === $actual_priority || 10 === $actual_priority;
		$ok               = $priority_ok
			&& $expected_priority === $actual_priority
			&& $expected_signature === $actual_signature;

		$details = array(
			'expectedPriority'  => $expected_priority,
			'actualPriority'    => $actual_priority,
			'expectedSignature' => $expected_signature,
			'actualSignature'   => $actual_signature,
		);

		if ( $ok ) {
			return self::pass( $seed, null, $invariant, '', $details );
		}

		return self::fail(
			$seed,
			null,
			$invariant,
			'',
			'pre_kses hook signature and wp_pre_kses_block_attributes priority remain stable',
			$details
		);
	}

	private static function hook_signature( string $hook_name ): array {
		if (
			! isset( $GLOBALS['wp_filter'] )
			|| ! is_array( $GLOBALS['wp_filter'] )
			|| ! array_key_exists( $hook_name, $GLOBALS['wp_filter'] )
		) {
			return array( 'exists' => false );
		}

		$hook = $GLOBALS['wp_filter'][ $hook_name ];
		if ( ! $hook instanceof \WP_Hook ) {
			return array(
				'exists' => true,
				'type'   => is_object( $hook ) ? get_class( $hook ) : gettype( $hook ),
			);
		}

		$callbacks = array();
		foreach ( $hook->callbacks as $priority => $priority_callbacks ) {
			foreach ( $priority_callbacks as $id => $callback ) {
				$callbacks[] = array(
					'priority'     => (int) $priority,
					'id'           => (string) $id,
					'function'     => self::hook_callback_summary( $callback['function'] ?? null ),
					'acceptedArgs' => (int) ( $callback['accepted_args'] ?? 0 ),
				);
			}
		}

		return array(
			'exists'    => true,
			'callbacks' => $callbacks,
		);
	}

	private static function hook_callback_summary( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return $target . '::' . (string) $callback[1];
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure:' . spl_object_hash( $callback );
		}

		if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			return get_class( $callback ) . '::__invoke';
		}

		return gettype( $callback );
	}

	private static function kses_global_names(): array {
		return array(
			'pass_allowed_html',
			'pass_allowed_protocols',
			'wp_current_filter',
		);
	}

	private static function check_global_restoration( int $seed, array $snapshot ): array {
		$restored = self::globals_match( $snapshot );
		$details  = array(
			'globals'  => array_keys( $snapshot ),
			'restored' => $restored,
		);

		if ( $restored ) {
			return self::pass( $seed, null, 'kses.surface-global-state-restored', implode( ',', array_keys( $snapshot ) ), $details );
		}

		return self::fail(
			$seed,
			null,
			'kses.surface-global-state-restored',
			implode( ',', array_keys( $snapshot ) ),
			'tracked KSES globals restored after surface execution',
			array(
				'before' => self::describe_global_snapshot( $snapshot ),
				'after'  => self::describe_global_snapshot( self::snapshot_globals( array_keys( $snapshot ) ) ),
			),
			$details
		);
	}

	private static function snapshot_globals( array $names ): array {
		$snapshot = array();
		foreach ( $names as $name ) {
			$snapshot[ $name ] = array(
				'exists' => array_key_exists( $name, $GLOBALS ),
				'value'  => array_key_exists( $name, $GLOBALS ) ? $GLOBALS[ $name ] : null,
			);
		}

		return $snapshot;
	}

	private static function restore_globals( array $snapshot ): void {
		foreach ( $snapshot as $name => $entry ) {
			if ( empty( $entry['exists'] ) ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $entry['value'];
			}
		}
	}

	private static function globals_match( array $snapshot ): bool {
		foreach ( $snapshot as $name => $entry ) {
			$exists = array_key_exists( $name, $GLOBALS );
			if ( $exists !== (bool) $entry['exists'] ) {
				return false;
			}
			if ( $exists && $GLOBALS[ $name ] != $entry['value'] ) {
				return false;
			}
		}

		return true;
	}

	private static function describe_global_snapshot( array $snapshot ): array {
		$described = array();
		foreach ( $snapshot as $name => $entry ) {
			$described[ $name ] = array(
				'exists' => (bool) ( $entry['exists'] ?? false ),
				'type'   => isset( $entry['value'] ) ? gettype( $entry['value'] ) : 'NULL',
				'sha1'   => sha1( serialize( $entry['value'] ?? null ) ),
			);
		}

		return $described;
	}

	private static function seed_from_context( $ctx ): int {
		foreach ( array( 'seed', 'getSeed' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					return self::normalize_seed( $ctx->$method() );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		foreach ( array( 'option', 'getOption', 'param', 'getParam' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					return self::normalize_seed( $ctx->$method( 'seed', 1 ) );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		if ( property_exists( $ctx, 'seed' ) ) {
			return self::normalize_seed( $ctx->seed );
		}

		return 1;
	}

	private static function int_from_context( $ctx, string $name, int $fallback, int $min, int $max ): int {
		$value = null;
		foreach ( array( 'option', 'getOption', 'param', 'getParam' ) as $method ) {
			if ( method_exists( $ctx, $method ) ) {
				try {
					$value = $ctx->$method( $name, $fallback );
					break;
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		if ( null === $value && property_exists( $ctx, $name ) ) {
			$value = $ctx->$name;
		}
		if ( null === $value ) {
			$value = $fallback;
		}

		if ( ! is_numeric( $value ) ) {
			$value = $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	private static function normalize_seed( $seed ): int {
		if ( is_numeric( $seed ) ) {
			return (int) $seed;
		}

		$hash = substr( sha1( (string) $seed ), 0, 8 );
		return (int) hexdec( $hash );
	}

	private static function rng( int $seed ): array {
		return array(
			'seed'    => (string) $seed,
			'counter' => 0,
			'buffer'  => '',
		);
	}

	private static function rng_bytes( array &$rng, int $length ): string {
		while ( strlen( $rng['buffer'] ) < $length ) {
			$rng['buffer'] .= hash( 'sha256', $rng['seed'] . ':' . $rng['counter'], true );
			++$rng['counter'];
		}

		$out           = substr( $rng['buffer'], 0, $length );
		$rng['buffer'] = substr( $rng['buffer'], $length );

		return $out;
	}

	private static function rng_uint32( array &$rng ): int {
		$parts = unpack( 'Nvalue', self::rng_bytes( $rng, 4 ) );
		return (int) $parts['value'];
	}

	private static function rng_int( array &$rng, int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		return $min + ( self::rng_uint32( $rng ) % ( $max - $min + 1 ) );
	}

	private static function rng_chance( array &$rng, int $numerator, int $denominator = 100 ): bool {
		return self::rng_int( $rng, 1, $denominator ) <= $numerator;
	}

	private static function rng_choice( array &$rng, array $values ) {
		return $values[ self::rng_int( $rng, 0, count( $values ) - 1 ) ];
	}

	private static function rng_weighted( array &$rng, array $weights ): string {
		$total = array_sum( $weights );
		$pick  = self::rng_int( $rng, 1, max( 1, (int) $total ) );
		$first = null;
		foreach ( $weights as $value => $weight ) {
			if ( null === $first ) {
				$first = $value;
			}
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return (string) $value;
			}
		}

		return (string) $first;
	}

	private static function pass( int $seed, ?int $case_index, string $invariant, string $input, array $details = array() ): array {
		$result = self::base_result( $seed, $case_index, $invariant, $input );
		$result['ok']     = true;
		$result['status'] = 'passed';
		if ( ! empty( $details ) ) {
			$result['details'] = self::compact_value( $details );
		}

		return $result;
	}

	private static function skip( int $seed, ?int $case_index, string $invariant, string $reason, string $input = '' ): array {
		$result = self::base_result( $seed, $case_index, $invariant, $input );
		$result['ok']     = true;
		$result['status'] = 'skipped';
		$result['reason'] = $reason;

		return $result;
	}

	private static function fail( int $seed, ?int $case_index, string $invariant, string $input, $expected, $actual, array $details = array() ): array {
		$result                  = self::base_result( $seed, $case_index, $invariant, $input );
		$result['ok']            = false;
		$result['status']        = 'failed';
		$result['failureClass']  = $details['failureClass'] ?? 'invariant-violation';
		$result['expected']      = self::compact_value( $expected );
		$result['actual']        = self::compact_value( $actual );
		unset( $details['failureClass'] );
		if ( ! empty( $details ) ) {
			$result['details'] = self::compact_value( $details );
		}

		return $result;
	}

	private static function throwable_result( int $seed, ?int $case_index, string $invariant, string $input, \Throwable $e, array $details = array() ): array {
		return self::fail(
			$seed,
			$case_index,
			$invariant,
			$input,
			'no Throwable',
			array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
			),
			$details + array( 'failureClass' => 'throwable' )
		);
	}

	private static function base_result( int $seed, ?int $case_index, string $invariant, string $input ): array {
		return array(
			'surface'      => self::NAME,
			'seed'         => $seed,
			'case'         => $case_index,
			'invariant'    => $invariant,
			'inputSha1'    => sha1( $input ),
			'inputLength'  => strlen( $input ),
			'inputPreview' => self::preview( $input ),
		);
	}

	private static function case_details( array $case, ?string $output = null ): array {
		$details = array(
			'profile'   => $case['profile'],
			'tag'       => $case['tag'],
			'protocols' => $case['protocols'],
		);
		if ( null !== $output ) {
			$details['outputSha1']    = sha1( $output );
			$details['outputLength']  = strlen( $output );
			$details['outputPreview'] = self::preview( $output );
		}

		return $details;
	}

	private static function preview( string $value, int $limit = self::PREVIEW_BYTES ): string {
		$slice = substr( $value, 0, $limit );
		$json  = json_encode( $slice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			$json = base64_encode( $slice );
		}

		return strlen( $value ) > $limit ? $json . '...' : $json;
	}

	private static function compact_value( $value ) {
		if ( is_string( $value ) ) {
			return self::preview( $value, 400 );
		}
		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 40 ) {
					$out['__truncated__'] = count( $value ) - $count;
					break;
				}
				$out[ $key ] = self::compact_value( $item );
				++$count;
			}
			return $out;
		}

		return $value;
	}
}
