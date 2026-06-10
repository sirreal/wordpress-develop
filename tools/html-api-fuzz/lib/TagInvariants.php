<?php
namespace HtmlApiFuzz;

class TagInvariants {
	public static function check( string $html, array $limits = array(), string $mode = Generator::MODE_FRAGMENT_BODY, string $fragment_context = 'body' ): array {
		HtmlApiBootstrap::load();
		$max_tokens = $limits['maxTokens'] ?? 2000;
		$failures   = array();
		$tokens     = 0;
		$normalize  = array(
			'status' => 'not-run',
			'ok'     => true,
		);

		try {
			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_token() ) {
				++$tokens;
				if ( $tokens > $max_tokens ) {
					$failures[] = array(
						'name'    => 'tag-token-limit-exceeded',
						'message' => 'WP_HTML_Tag_Processor exceeded token limit.',
					);
					break;
				}

				$type = $processor->get_token_type();
				$name = $processor->get_token_name();
				if ( null === $type ) {
					$failures[] = array(
						'name'    => 'null-token-type-after-next-token',
						'message' => 'get_token_type() returned null after next_token().',
					);
					break;
				}
				if ( null === $name ) {
					$failures[] = array(
						'name'    => 'null-token-name-after-next-token',
						'message' => 'get_token_name() returned null after next_token().',
					);
					break;
				}

				if ( '#tag' === $type && ! $processor->is_tag_closer() ) {
					$tag = $processor->get_tag();
					if ( null === $tag ) {
						$failures[] = array(
							'name'    => 'null-tag-for-tag-token',
							'message' => 'get_tag() returned null for a tag token.',
						);
						break;
					}
					$attrs = $processor->get_attribute_names_with_prefix( '' );
					if ( is_array( $attrs ) ) {
						foreach ( $attrs as $attr ) {
							$processor->get_attribute( $attr );
							$processor->get_qualified_attribute_name( $attr );
						}
					}
					if ( null !== $processor->get_attribute( 'class' ) ) {
						foreach ( $processor->class_list() as $_class_name ) {
							// Iteration itself is the invariant check.
						}
					}
				}

				$processor->get_modifiable_text();
			}

			if ( $processor->get_updated_html() !== $html ) {
				$failures[] = array(
					'name'    => 'updated-html-changed-without-edits',
					'message' => 'get_updated_html() changed HTML even though no edits were queued.',
				);
			}

			$mutation = self::check_simple_mutation( $html, $max_tokens );
			if ( ! $mutation['ok'] ) {
				$failures[] = $mutation['failure'];
			}

			$seek = self::check_seek_consistency( $html, $max_tokens, $tokens );
			if ( ! $seek['ok'] ) {
				$failures[] = $seek['failure'];
			}
		} catch ( \Throwable $e ) {
			$failures[] = array(
				'name'      => 'tag-processor-throwable',
				'message'   => $e->getMessage(),
				'throwable' => get_class( $e ),
			);
		}

		if ( self::has_resource_limit_failure( $failures ) ) {
			$normalize = array(
				'status' => 'skipped-resource-limit',
				'ok'     => true,
			);
		} else {
			$normalize = self::check_normalize_idempotence( $html, $mode, $fragment_context );
		}

		return array(
			'ok'         => empty( $failures ),
			'failures'   => $failures,
			'tokenCount' => $tokens,
			'normalize'  => $normalize,
		);
	}

	private static function has_resource_limit_failure( array $failures ): bool {
		foreach ( $failures as $failure ) {
			if ( in_array( $failure['name'] ?? null, array( 'tag-token-limit-exceeded', 'mutation-token-limit-exceeded' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function check_normalize_idempotence( string $html, string $mode, string $fragment_context ): array {
		$errors = array();
		$normalized = null;
		$normalized_twice = null;
		$throwable = null;

		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$errors ): bool {
				$errors[] = "{$errno}: {$errstr}";
				return true;
			}
		);

		try {
			$normalized = self::normalize_html( $html, $mode, $fragment_context );
			$normalized_twice = is_string( $normalized ) ? self::normalize_html( $normalized, $mode, $fragment_context ) : null;
		} catch ( \Throwable $e ) {
			$throwable = $e;
		} finally {
			restore_error_handler();
		}

		if ( null !== $throwable ) {
			return array(
				'ok'        => false,
				'status'    => 'failed',
				'mode'      => $mode,
				'api'       => self::normalize_api_for_mode( $mode, $fragment_context ),
				'failure' => array(
					'name'      => 'normalize-throwable',
					'message'   => $throwable->getMessage(),
					'throwable' => get_class( $throwable ),
				),
				'throwable' => get_class( $throwable ),
			);
		}

		if ( ! empty( $errors ) ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'mode'   => $mode,
				'api'    => self::normalize_api_for_mode( $mode, $fragment_context ),
				'failure' => array(
					'name'    => 'normalize-native-error',
					'message' => 'WP_HTML_Processor::normalize() emitted native PHP errors.',
					'errors'  => $errors,
				),
				'errors' => $errors,
			);
		}

		if ( null === $normalized ) {
			return array(
				'ok'          => true,
				'status'      => 'unsupported',
				'mode'        => $mode,
				'api'         => self::normalize_api_for_mode( $mode, $fragment_context ),
				'inputLength' => strlen( $html ),
			);
		}

		if ( null === $normalized_twice ) {
			return array(
				'ok'               => false,
				'status'           => 'failed',
				'mode'             => $mode,
				'api'              => self::normalize_api_for_mode( $mode, $fragment_context ),
				'normalizedLength' => strlen( $normalized ),
				'normalizedSha1'   => sha1( $normalized ),
				'failure' => array(
					'name'              => 'normalize-output-unsupported',
					'message'           => 'WP_HTML_Processor::normalize() returned HTML that normalize() could not normalize again.',
					'normalizedLength'  => strlen( $normalized ),
					'normalizedSha1'    => sha1( $normalized ),
					'normalizedPreview' => preview_bytes( $normalized ),
				),
			);
		}

		if ( $normalized !== $normalized_twice ) {
			$first_difference = self::first_string_difference( $normalized, $normalized_twice );
			return array(
				'ok'                    => false,
				'status'                => 'failed',
				'mode'                  => $mode,
				'api'                   => self::normalize_api_for_mode( $mode, $fragment_context ),
				'normalizedLength'      => strlen( $normalized ),
				'normalizedTwiceLength' => strlen( $normalized_twice ),
				'normalizedSha1'        => sha1( $normalized ),
				'normalizedTwiceSha1'   => sha1( $normalized_twice ),
				'firstDifference'       => $first_difference,
				'failure' => array(
					'name'                   => 'normalize-not-idempotent',
					'message'                => 'Normalizing already-normalized HTML changed the output.',
					'normalizedLength'       => strlen( $normalized ),
					'normalizedTwiceLength'  => strlen( $normalized_twice ),
					'normalizedSha1'         => sha1( $normalized ),
					'normalizedTwiceSha1'    => sha1( $normalized_twice ),
					'normalizedPreview'      => preview_bytes( $normalized ),
					'normalizedTwicePreview' => preview_bytes( $normalized_twice ),
					'firstDifference'        => $first_difference,
				),
			);
		}

		return array(
			'ok'               => true,
			'status'           => 'idempotent',
			'mode'             => $mode,
			'api'              => self::normalize_api_for_mode( $mode, $fragment_context ),
			'inputLength'      => strlen( $html ),
			'normalizedLength' => strlen( $normalized ),
			'normalizedSha1'   => sha1( $normalized ),
			// In-memory only: the worker uses this for the tree-preservation
			// check and strips it before persisting results.
			'normalizedHtml'   => $normalized,
		);
	}

	private static function normalize_html( string $html, string $mode, string $fragment_context ): ?string {
		if ( Generator::MODE_FULL_DOCUMENT === $mode ) {
			$processor = \WP_HTML_Processor::create_full_parser( $html );
			return null === $processor ? null : $processor->serialize();
		}

		if ( 'body' !== $fragment_context ) {
			$processor = \WP_HTML_Processor::create_fragment( $html, "<{$fragment_context}>" );
			return null === $processor ? null : $processor->serialize();
		}

		return \WP_HTML_Processor::normalize( $html );
	}

	private static function normalize_api_for_mode( string $mode, string $fragment_context = 'body' ): string {
		if ( Generator::MODE_FULL_DOCUMENT === $mode ) {
			return 'create_full_parser()->serialize()';
		}
		if ( 'body' !== $fragment_context ) {
			return "create_fragment(<{$fragment_context}>)->serialize()";
		}
		return 'normalize()';
	}

	private static function first_string_difference( string $a, string $b ): array {
		$max = min( strlen( $a ), strlen( $b ) );
		$offset = 0;
		while ( $offset < $max && $a[ $offset ] === $b[ $offset ] ) {
			++$offset;
		}

		$window_start = max( 0, $offset - 16 );
		return array(
			'firstByteOffset'        => $offset,
			'normalizedDiffHex'      => bin2hex( substr( $a, $window_start, 64 ) ),
			'normalizedTwiceDiffHex' => bin2hex( substr( $b, $window_start, 64 ) ),
		);
	}

	/**
	 * Verifies that seeking back to a bookmarked token reproduces the exact
	 * token stream that followed it on the first pass.
	 *
	 * The bookmark target is derived deterministically from the input so the
	 * checked position varies across seeds without extra generator plumbing.
	 */
	private static function check_seek_consistency( string $html, int $max_tokens, int $token_count ): array {
		if ( $token_count < 1 ) {
			return array( 'ok' => true );
		}

		$target = 1 + ( crc32( $html ) % min( $token_count, $max_tokens ) );

		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = 0;
		$bookmarked = false;
		$first_pass = array();
		while ( $processor->next_token() ) {
			++$index;
			if ( $index > $max_tokens ) {
				break;
			}
			if ( ! $bookmarked && $index === $target ) {
				if ( ! $processor->set_bookmark( 'fuzz-seek' ) ) {
					return array(
						'ok'      => false,
						'failure' => array(
							'name'    => 'seek-bookmark-failed',
							'message' => 'set_bookmark() failed on a token reached by next_token().',
						),
					);
				}
				$bookmarked = true;
			}
			if ( $bookmarked ) {
				$first_pass[] = self::token_fingerprint( $processor );
			}
		}

		if ( ! $bookmarked ) {
			return array( 'ok' => true );
		}

		if ( ! $processor->seek( 'fuzz-seek' ) ) {
			return array(
				'ok'      => false,
				'failure' => array(
					'name'    => 'seek-failed',
					'message' => 'seek() failed for a bookmark that set_bookmark() accepted.',
				),
			);
		}

		$second_pass   = array( self::token_fingerprint( $processor ) );
		$second_tokens = 1;
		while ( $second_tokens < count( $first_pass ) && $processor->next_token() ) {
			++$second_tokens;
			$second_pass[] = self::token_fingerprint( $processor );
		}

		if ( $first_pass !== $second_pass ) {
			$divergence = 0;
			$limit      = min( count( $first_pass ), count( $second_pass ) );
			while ( $divergence < $limit && $first_pass[ $divergence ] === $second_pass[ $divergence ] ) {
				++$divergence;
			}
			return array(
				'ok'      => false,
				'failure' => array(
					'name'             => 'seek-token-stream-mismatch',
					'message'          => 'Re-scanning after seek() produced a different token stream.',
					'bookmarkIndex'    => $target,
					'divergenceOffset' => $divergence,
					'firstPassCount'   => count( $first_pass ),
					'secondPassCount'  => count( $second_pass ),
					'firstFingerprint' => $first_pass[ $divergence ] ?? null,
					'secondFingerprint' => $second_pass[ $divergence ] ?? null,
				),
			);
		}

		return array( 'ok' => true );
	}

	private static function token_fingerprint( \WP_HTML_Tag_Processor $processor ): string {
		$parts = array(
			(string) $processor->get_token_type(),
			(string) $processor->get_token_name(),
			$processor->is_tag_closer() ? '/' : '',
			(string) $processor->get_modifiable_text(),
		);

		if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
			$names = $processor->get_attribute_names_with_prefix( '' );
			if ( is_array( $names ) ) {
				foreach ( $names as $name ) {
					$value   = $processor->get_attribute( $name );
					$parts[] = $name . '=' . ( true === $value ? '(true)' : (string) $value );
				}
			}
		}

		return sha1( implode( "\x1f", $parts ) );
	}

	private static function check_simple_mutation( string $html, int $max_tokens ): array {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return array( 'ok' => true );
		}

		if ( ! $processor->set_attribute( 'data-fuzz', '1' ) ) {
			return array( 'ok' => true );
		}

		$updated = $processor->get_updated_html();
		$scan    = new \WP_HTML_Tag_Processor( $updated );
		$tokens  = 0;
		$checked_first_tag = false;
		while ( $scan->next_token() ) {
			++$tokens;
			if ( $tokens > $max_tokens ) {
				return array(
					'ok'      => false,
					'failure' => array(
						'name'    => 'mutation-token-limit-exceeded',
						'message' => 'A simple set_attribute() mutation produced HTML that exceeded the token limit.',
					),
				);
			}

			if ( ! $checked_first_tag && '#tag' === $scan->get_token_type() && ! $scan->is_tag_closer() ) {
				$checked_first_tag = true;
				if ( '1' !== $scan->get_attribute( 'data-fuzz' ) ) {
					return array(
						'ok'      => false,
						'failure' => array(
							'name'    => 'mutation-attribute-missing',
							'message' => 'set_attribute() reported success, but the updated first tag does not contain data-fuzz="1".',
						),
					);
				}
			}
		}

		if ( ! $checked_first_tag ) {
			return array(
				'ok'      => false,
				'failure' => array(
					'name'    => 'mutation-removed-first-tag',
					'message' => 'A simple set_attribute() mutation removed the first tag from the updated HTML.',
				),
			);
		}

		return array( 'ok' => true );
	}
}
