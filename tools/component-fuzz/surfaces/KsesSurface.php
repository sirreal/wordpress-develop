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

		$results = array_merge( $results, self::check_allowed_html_contracts( $seed ) );

		$rng = self::rng( $seed );
		for ( $case_index = 0; $case_index < $case_count; ++$case_index ) {
			$case    = self::generate_case( $rng, $case_index );
			$results = array_merge( $results, self::check_case( $seed, $case_index, $case ) );
		}

		return $results;
	}

	private static function check_case( int $seed, int $case_index, array $case ): array {
		$results = array();
		$results = array_merge( $results, self::check_wp_kses_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_wp_kses_post_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_bad_protocol_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_url_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_safecss_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_nohtml_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_hair_parse_invariants( $seed, $case_index, $case ) );
		$results = array_merge( $results, self::check_one_attr_invariants( $seed, $case_index, $case ) );

		return $results;
	}

	private static function check_allowed_html_contracts( int $seed ): array {
		$results = array();

		try {
			$contexts = array( 'post', 'data', 'strip', 'entities' );
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
			if ( empty( $violations ) ) {
				$results[] = self::pass( $seed, $case_index, 'wp_kses_post.uri-attributes-safe', $html, self::case_details( $case, $post ) );
			} else {
				$results[] = self::fail(
					$seed,
					$case_index,
					'wp_kses_post.uri-attributes-safe',
					$html,
					'no forbidden URI protocols in post-policy output attributes',
					$violations,
					self::case_details( $case, $post )
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
			return $case;
		}

		$profile = self::rng_weighted(
			$rng,
			array(
				'protocol-attributes' => 24,
				'css-url-values'      => 18,
				'foreign-content'     => 14,
				'malformed-markup'    => 16,
				'nested-tags'         => 18,
				'byte-edges'          => 10,
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
				$html = self::random_nodes( $rng, 3, $urls, $css );
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

		return array(
			'profile'   => $profile,
			'html'      => $html,
			'urls'      => $urls,
			'css'       => $css,
			'attrs'     => $attrs,
			'tag'       => $tag,
			'protocols' => $protocols,
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

			$tag   = self::rng_choice( $rng, array( 'a', 'div', 'span', 'p', 'strong', 'em', 'code', 'blockquote', 'ul', 'li', 'table', 'tr', 'td', 'script', 'style', 'iframe', 'custom-element' ) );
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
			'poster',
			'cite',
			'background',
			'title',
			'alt',
			'class',
			'id',
			'style',
			'onclick',
			'onload',
			'data-safe',
			'data-evil.dot',
			'aria-label',
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

			if ( in_array( strtolower( $name ), self::uri_attributes(), true ) || 'xlink:href' === strtolower( $name ) ) {
				$value = self::rng_choice( $rng, $urls );
			} elseif ( 'style' === $name ) {
				$value = self::random_css( $rng, $urls );
			} else {
				$value = self::random_attr_value( $rng );
			}

			$quote = self::rng_choice( $rng, array( '"', "'", '' ) );
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
						'expression(alert(1))',
						'2px}',
						'\\2px',
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
				'jav&#x09;ascript&#58;alert(1)',
				'javascript&#0000058alert(1)',
				'feed:javascript:alert(1)',
				'feed:feed:javascript:alert(1)',
				'data:text/html,<svg/onload=alert(1)>',
				'vbscript:msgbox(1)',
				"\x00javascript:alert(1)",
				'http://[::FFFF::127.0.0.1]/?foo[bar]=baz',
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

	private static function post_output_security_violations( string $html, array $protocols ): array {
		$allowed = function_exists( 'wp_kses_allowed_html' ) ? \wp_kses_allowed_html( 'post' ) : array();
		$policy_violations = is_array( $allowed ) ? self::policy_violations( $html, $allowed, $protocols, false ) : array();

		return $policy_violations;
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

			if ( isset( $disallowed_props[ $lower ] ) ) {
				$violations[] = array(
					'type'     => 'disallowed-property',
					'property' => $prop,
				);
			}

			$is_custom_property = 1 === preg_match( '/^--[A-Za-z0-9-_]+$/', $prop );
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
