<?php
/**
 * Block fuzz oracles.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

namespace BlockFuzz;

/**
 * Runs block parser, serializer, processor, and filtering oracles.
 */
class Fuzzer {
	/**
	 * Runs all oracles for one input.
	 *
	 * @param string $input    Block document.
	 * @param array  $metadata Seed metadata.
	 * @param array  $options  Oracle options.
	 * @return array Result record.
	 */
	public static function run( $input, $metadata = array(), $options = array() ) {
		Bootstrap::load();

		$started  = microtime( true );
		$failures = array();
		$notes    = array();
		$once     = null;

		$max_serialized_bytes = isset( $options['maxSerializedBytes'] )
			? (int) $options['maxSerializedBytes']
			: max( 65536, strlen( $input ) * 16 + 1024 );
		$max_tokens           = isset( $options['maxTokens'] ) ? (int) $options['maxTokens'] : 2048;

		self::capture(
			'parse-serialize-fixed-point',
			function () use ( $input, &$once, $max_serialized_bytes ) {
				$parsed = parse_blocks( $input );
				$once   = serialize_blocks( $parsed );
				$twice  = serialize_blocks( parse_blocks( $once ) );

				if ( strlen( $once ) > $max_serialized_bytes ) {
					throw new \RuntimeException(
						'Serialized output exceeded bound: ' . strlen( $once ) . " > {$max_serialized_bytes}."
					);
				}

				if ( $once !== $twice ) {
					throw new OracleFailure(
						'not-fixed-point',
						array(
							'once'  => self::value_summary( $once ),
							'twice' => self::value_summary( $twice ),
						)
					);
				}
			},
			$failures
		);

		if ( null !== $once ) {
			self::capture(
				'serialized-attribute-safety',
				function () use ( $once ) {
					self::assert_serialized_attributes_are_safe( $once );
				},
				$failures
			);
		}

		self::capture(
			'processor-token-bound',
			function () use ( $input, $max_tokens ) {
				self::assert_processor_scan_is_bounded( $input, $max_tokens );
			},
			$failures
		);

		self::capture(
			'processor-extraction-bound',
			function () use ( $input, $max_tokens ) {
				self::assert_processor_extraction_is_bounded( $input, $max_tokens );
			},
			$failures
		);

		$expect_processor_agreement = array_key_exists( 'expectProcessorAgreement', $metadata )
			? (bool) $metadata['expectProcessorAgreement']
			: self::is_well_formed_for_processor_agreement( $input, $max_tokens );

		if ( $expect_processor_agreement ) {
			self::capture(
				'processor-parser-agreement',
				function () use ( $input ) {
					self::assert_processor_matches_parser( $input );
				},
				$failures
			);
		} else {
			$notes[] = array(
				'oracle' => 'processor-parser-agreement',
				'status' => 'skipped',
				'reason' => 'input is intentionally malformed for extraction comparison',
			);
		}

		$filtered = null;
		self::capture(
			'filter-block-content-idempotence',
			function () use ( $input, $max_serialized_bytes ) {
				self::assert_filter_block_content_is_idempotent( $input, $max_serialized_bytes );
			},
			$failures
		);

		self::capture(
			'filter-block-content-attrs-clean',
			function () use ( $input, &$filtered ) {
				if ( null === $filtered ) {
					$filtered = filter_block_content( $input );
				}

				self::assert_filtered_block_attrs_are_kses_clean( $filtered );
			},
			$failures
		);

		$result = array(
			'ok'         => empty( $failures ),
			'status'     => empty( $failures ) ? 'pass' : 'fail',
			'metadata'   => $metadata,
			'summary'    => array(
				'inputBytes' => strlen( $input ),
				'inputHash'  => hash( 'sha256', $input ),
				'preview'    => preview_bytes( $input ),
				'durationMs' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			),
			'notes'      => $notes,
			'failures'   => $failures,
		);

		if ( ! empty( $failures ) ) {
			$result['signature'] = failure_signature( $failures[0] );
		}

		return $result;
	}

	/**
	 * Runs fixture-corpus serialization checks.
	 *
	 * @param string $fixtures_dir Fixture directory.
	 * @return array Result record.
	 */
	public static function run_fixture_corpus( $fixtures_dir ) {
		Bootstrap::load();

		$failures = array();
		$checked  = 0;

		foreach ( glob( rtrim( $fixtures_dir, '/\\' ) . '/*.serialized.html' ) as $serialized_path ) {
			++$checked;
			$expected = file_get_contents( $serialized_path );
			$actual   = serialize_blocks( parse_blocks( $expected ) );

			if ( $actual !== $expected ) {
				$failures[] = array(
					'oracle' => 'fixture-canonical-serialization',
					'reason' => 'fixture-mismatch',
					'detail' => basename( $serialized_path ),
					'expected' => self::value_summary( $expected ),
					'actual' => self::value_summary( $actual ),
				);
				continue;
			}

			$again = serialize_blocks( parse_blocks( $actual ) );
			if ( $actual !== $again ) {
				$failures[] = array(
					'oracle' => 'fixture-canonical-serialization',
					'reason' => 'fixture-not-fixed-point',
					'detail' => basename( $serialized_path ),
					'once'   => self::value_summary( $actual ),
					'twice'  => self::value_summary( $again ),
				);
			}
		}

		$result = array(
			'ok'       => empty( $failures ),
			'status'   => empty( $failures ) ? 'pass' : 'fail',
			'metadata' => array(
				'fixturesDir' => $fixtures_dir,
				'checked'     => $checked,
			),
			'failures' => $failures,
		);

		if ( ! empty( $failures ) ) {
			$result['signature'] = failure_signature( $failures[0] );
		}

		return $result;
	}

	/**
	 * Converts a failure result to a compact assertion message.
	 *
	 * @param array $result Result record.
	 * @return string Message.
	 */
	public static function failure_message( $result ) {
		if ( ! empty( $result['ok'] ) ) {
			return 'No failure.';
		}

		return json_encode_safe(
			array(
				'metadata'  => $result['metadata'] ?? array(),
				'summary'   => $result['summary'] ?? array(),
				'signature' => $result['signature'] ?? null,
				'failures'  => $result['failures'] ?? array(),
			)
		);
	}

	/**
	 * Runs one oracle and records thrown failures.
	 *
	 * @param string   $oracle   Oracle name.
	 * @param callable $callback Callback.
	 * @param array    $failures Failure list.
	 */
	private static function capture( $oracle, $callback, &$failures ) {
		try {
			$callback();
		} catch ( OracleFailure $failure ) {
			$record           = $failure->to_array();
			$record['oracle'] = $oracle;
			$failures[]       = $record;
		} catch ( \Throwable $throwable ) {
			$failures[] = array(
				'oracle' => $oracle,
				'reason' => 'throwable',
				'detail' => get_class( $throwable ) . ': ' . $throwable->getMessage(),
			);
		}
	}

	/**
	 * Checks that serialized delimiters do not expose unsafe attribute bytes.
	 *
	 * @param string $serialized Serialized block document.
	 */
	private static function assert_serialized_attributes_are_safe( $serialized ) {
		$processor = new \WP_Block_Processor( $serialized );

		while ( $processor->next_delimiter() ) {
			if ( \WP_Block_Processor::CLOSER === $processor->get_delimiter_type() ) {
				continue;
			}

			$span      = $processor->get_span();
			$delimiter = substr( $serialized, $span->start, $span->length );
			$payload   = self::attribute_payload_from_delimiter( $delimiter );

			if ( '' === $payload ) {
				continue;
			}

			foreach ( array( '--', '<', '>', '&' ) as $unsafe ) {
				if ( false !== strpos( $payload, $unsafe ) ) {
					throw new OracleFailure(
						'unsafe-attribute-delimiter-byte',
						array(
							'unsafe'    => $unsafe,
							'delimiter' => preview_bytes( $delimiter ),
						)
					);
				}
			}

			$unsafe_escape = self::find_unsafe_json_string_escape( $payload );
			if ( null !== $unsafe_escape ) {
				throw new OracleFailure(
					'unsafe-attribute-delimiter-escape',
					array(
						'escape'    => $unsafe_escape,
						'delimiter' => preview_bytes( $delimiter ),
					)
				);
			}
		}

		// Incomplete block-looking text in serialized freeform content is not a
		// serialized delimiter and is covered by the non-fatal parser oracles.
	}

	/**
	 * Extracts the raw attributes payload from a serialized block delimiter.
	 *
	 * @param string $delimiter Delimiter text.
	 * @return string Attribute payload or empty string.
	 */
	private static function attribute_payload_from_delimiter( $delimiter ) {
		if ( strlen( $delimiter ) < 8 || '<!--' !== substr( $delimiter, 0, 4 ) || '-->' !== substr( $delimiter, -3 ) ) {
			return '';
		}

		$body = trim( substr( $delimiter, 4, -3 ) );
		if ( 0 !== strpos( $body, 'wp:' ) ) {
			return '';
		}

		if ( '/' === substr( $body, -1 ) ) {
			$body = rtrim( substr( $body, 0, -1 ) );
		}

		$space_at = strpos( $body, ' ' );
		if ( false === $space_at ) {
			return '';
		}

		return trim( substr( $body, $space_at + 1 ) );
	}

	/**
	 * Finds unsafe quote/backslash escapes inside JSON strings.
	 *
	 * `serialize_block_attributes()` should convert literal quotes and
	 * backslashes in values to unicode escapes, not `\"` or `\\`.
	 *
	 * @param string $payload JSON payload.
	 * @return string|null Unsafe escape, if found.
	 */
	private static function find_unsafe_json_string_escape( $payload ) {
		$length    = strlen( $payload );
		$in_string = false;

		for ( $i = 0; $i < $length; ++$i ) {
			$char = $payload[ $i ];

			if ( ! $in_string ) {
				if ( '"' === $char ) {
					$in_string = true;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_string = false;
				continue;
			}

			if ( '\\' !== $char ) {
				continue;
			}

			$next = $i + 1 < $length ? $payload[ $i + 1 ] : '';
			if ( '"' === $next || '\\' === $next ) {
				return '\\' . $next;
			}

			if ( 'u' === $next && $i + 5 < $length && preg_match( '/^[0-9a-fA-F]{4}$/', substr( $payload, $i + 2, 4 ) ) ) {
				$i += 5;
				continue;
			}

			if ( in_array( $next, array( '/', 'b', 'f', 'n', 'r', 't' ), true ) ) {
				++$i;
				continue;
			}

			return '\\' . $next;
		}

		return null;
	}

	/**
	 * Verifies the processor token loop terminates inside a bound.
	 *
	 * @param string $input      Input document.
	 * @param int    $max_tokens Maximum tokens.
	 */
	private static function assert_processor_scan_is_bounded( $input, $max_tokens ) {
		$processor = new \WP_Block_Processor( $input );
		$tokens    = 0;

		while ( $processor->next_token() ) {
			++$tokens;
			if ( $tokens > $max_tokens ) {
				throw new OracleFailure(
					'processor-token-limit-exceeded',
					array(
						'maxTokens' => $max_tokens,
					)
				);
			}
		}
	}

	/**
	 * Verifies the processor extraction loop terminates inside a bound.
	 *
	 * @param string $input      Input document.
	 * @param int    $max_tokens Maximum extracted blocks.
	 */
	private static function assert_processor_extraction_is_bounded( $input, $max_tokens ) {
		$processor   = new \WP_Block_Processor( $input );
		$extractions = 0;

		while ( $processor->next_block( '*' ) ) {
			++$extractions;
			if ( $extractions > $max_tokens ) {
				throw new OracleFailure(
					'processor-extraction-limit-exceeded',
					array(
						'maxTokens' => $max_tokens,
					)
				);
			}

			$processor->extract_full_block_and_advance();
		}
	}

	/**
	 * Verifies processor extraction matches parse_blocks().
	 *
	 * @param string $input Input document.
	 */
	private static function assert_processor_matches_parser( $input ) {
		$processor = new \WP_Block_Processor( $input );
		$blocks    = array();

		while ( $processor->next_block( '*' ) ) {
			$blocks[] = $processor->extract_full_block_and_advance();
		}

		if ( \WP_Block_Processor::INCOMPLETE_INPUT === $processor->get_last_error() ) {
			throw new OracleFailure( 'processor-incomplete-input', array() );
		}

		$parsed = parse_blocks( $input );
		if ( $parsed !== $blocks ) {
			throw new OracleFailure(
				'processor-parser-mismatch',
				array(
					'parsed'    => self::value_summary( $parsed ),
					'processed' => self::value_summary( $blocks ),
				)
			);
		}
	}

	/**
	 * Checks whether a document is suitable for processor/parser comparison.
	 *
	 * @param string $input      Input document.
	 * @param int    $max_tokens Maximum delimiter tokens.
	 * @return bool Whether the document is suitable.
	 */
	public static function is_well_formed_for_processor_agreement( $input, $max_tokens = 2048 ) {
		$processor = new \WP_Block_Processor( $input );
		$stack     = array();
		$tokens    = 0;

		while ( $processor->next_delimiter() ) {
			++$tokens;
			if ( $tokens > $max_tokens ) {
				return false;
			}

			$type = $processor->get_delimiter_type();
			if ( \WP_Block_Processor::OPENER === $type || \WP_Block_Processor::VOID === $type ) {
				$processor->allocate_and_return_parsed_attributes();
				if ( JSON_ERROR_NONE !== $processor->get_last_json_error() ) {
					return false;
				}
			}

			if ( \WP_Block_Processor::OPENER === $type ) {
				$stack[] = $processor->get_block_type();
				continue;
			}

			if ( \WP_Block_Processor::CLOSER === $type ) {
				if ( empty( $stack ) || end( $stack ) !== $processor->get_block_type() ) {
					return false;
				}
				array_pop( $stack );
			}
		}

		if ( \WP_Block_Processor::INCOMPLETE_INPUT === $processor->get_last_error() ) {
			return false;
		}

		return empty( $stack );
	}

	/**
	 * Checks block filtering invariants.
	 *
	 * @param string $input                Input document.
	 * @param int    $max_serialized_bytes Maximum filtered output bytes.
	 */
	private static function assert_filter_block_content_is_idempotent( $input, $max_serialized_bytes ) {
		$filtered = filter_block_content( $input );
		$again    = filter_block_content( $filtered );

		if ( strlen( $filtered ) > $max_serialized_bytes ) {
			throw new OracleFailure(
				'filtered-output-limit-exceeded',
				array(
					'bytes' => strlen( $filtered ),
					'limit' => $max_serialized_bytes,
				)
			);
		}

		if ( $filtered !== $again ) {
			throw new OracleFailure(
				'filter-not-idempotent',
				array(
					'once'  => self::value_summary( $filtered ),
					'twice' => self::value_summary( $again ),
				)
			);
		}
	}

	/**
	 * Checks that filtered block attributes are KSES-clean.
	 *
	 * @param string $filtered Filtered block document.
	 */
	private static function assert_filtered_block_attrs_are_kses_clean( $filtered ) {
		foreach ( parse_blocks( $filtered ) as $block_index => $block ) {
			self::assert_block_attrs_are_kses_clean( $block, 'blocks[' . $block_index . ']' );
		}
	}

	/**
	 * Recursively checks that parsed block attributes are KSES-clean.
	 *
	 * @param array  $block Block.
	 * @param string $path  Debug path.
	 */
	private static function assert_block_attrs_are_kses_clean( $block, $path ) {
		if ( isset( $block['attrs'] ) ) {
			self::assert_value_is_kses_clean( $block['attrs'], $path . '.attrs' );
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $index => $inner_block ) {
				self::assert_block_attrs_are_kses_clean( $inner_block, $path . '.innerBlocks[' . $index . ']' );
			}
		}
	}

	/**
	 * Recursively checks one attribute value.
	 *
	 * @param mixed  $value Attribute value.
	 * @param string $path  Debug path.
	 */
	private static function assert_value_is_kses_clean( $value, $path ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner_value ) {
				self::assert_value_is_kses_clean( $key, $path . '.key' );
				self::assert_value_is_kses_clean( $inner_value, $path . '[' . self::path_fragment( $key ) . ']' );
			}
			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		$filtered = wp_kses( $value, 'post' );
		if ( $filtered !== $value ) {
			throw new OracleFailure(
				'filtered-attribute-not-kses-clean',
				array(
					'path'     => $path,
					'original' => self::value_summary( $value ),
					'filtered' => self::value_summary( $filtered ),
				)
			);
		}
	}

	/**
	 * Summarizes a value without storing large payloads.
	 *
	 * @param mixed $value Value.
	 * @return array Summary.
	 */
	private static function value_summary( $value ) {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			$json = serialize( $value );
		}

		return array(
			'bytes'   => strlen( $json ),
			'sha256'  => hash( 'sha256', $json ),
			'preview' => preview_bytes( $json, 160 ),
		);
	}

	/**
	 * Formats a path fragment.
	 *
	 * @param mixed $value Value.
	 * @return string Path fragment.
	 */
	private static function path_fragment( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return gettype( $value );
	}
}

/**
 * Internal exception for oracle failures.
 */
class OracleFailure extends \RuntimeException {
	/**
	 * Failure reason.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Failure detail.
	 *
	 * @var array
	 */
	private $detail;

	/**
	 * Constructor.
	 *
	 * @param string $reason Failure reason.
	 * @param array  $detail Failure detail.
	 */
	public function __construct( $reason, $detail ) {
		parent::__construct( $reason );

		$this->reason = $reason;
		$this->detail = $detail;
	}

	/**
	 * Returns an array record.
	 *
	 * @return array Failure record.
	 */
	public function to_array() {
		return array(
			'reason' => $this->reason,
			'detail' => $this->detail,
		);
	}
}
