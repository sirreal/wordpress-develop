<?php
namespace CssDeclarationFuzz;

class Worker {
	/** Runs one deterministic case and returns all invariant failures. */
	public static function run_case( int $seed, ?string $override_style = null ): array {
		Bootstrap::load();

		$case = CaseGenerator::generate( $seed );
		if ( null !== $override_style ) {
			$case = array(
				'bucket'   => 'replay-bytes',
				'style'    => $override_style,
				'expected' => null,
			);
		}

		$style       = $case['style'];
		$failures    = array();
		$token_types = array();
		$operations  = array();
		$record      = static function ( string $invariant, array $detail = array() ) use ( &$failures ): void {
			$failures[] = array(
				'invariant' => $invariant,
				'signature' => $invariant,
				'detail'    => $detail,
			);
		};
		$check       = static function ( bool $condition, string $invariant, array $detail = array() ) use ( $record ): void {
			if ( ! $condition ) {
				$record( $invariant, $detail );
			}
		};
		$guard       = static function ( string $phase, callable $callback ) use ( $record ): void {
			try {
				$callback();
			} catch ( \Throwable $e ) {
				$record( $phase . '-exception', array( 'error' => self::describe_throwable( $e ) ) );
			}
		};

		$guard(
			'token-partition',
			static function () use ( $style, $check, &$token_types ): void {
				$processor = \WP_CSS_Token_Processor::create( $style );
				$check( null !== $processor, 'token-create-rejected' );
				if ( null === $processor ) {
					return;
				}

				$expected_start = 0;
				$iterations     = 0;
				$limit          = max( 32, strlen( $style ) * 2 + 32 );
				while ( $processor->next_token() ) {
					if ( ++$iterations > $limit ) {
						$check( false, 'token-no-forward-progress', array( 'limit' => $limit ) );
						break;
					}

					$type   = $processor->get_token_type();
					$start  = $processor->get_token_start();
					$length = $processor->get_token_length();
					$raw    = $processor->get_unnormalized_token();
					$check( is_string( $type ), 'token-type-null', array( 'iteration' => $iterations ) );
					$check( is_int( $start ) && is_int( $length ) && $length > 0, 'token-invalid-range', array( 'start' => $start, 'length' => $length ) );
					if ( ! is_int( $start ) || ! is_int( $length ) || $length <= 0 ) {
						break;
					}
					$check( $expected_start === $start, 'token-range-gap-or-overlap', array( 'expectedStart' => $expected_start, 'actualStart' => $start ) );
					$check( substr( $style, $start, $length ) === $raw, 'token-raw-range-mismatch', array( 'start' => $start, 'length' => $length ) );
					$expected_start = $start + $length;
					$token_types[ (string) $type ] = ( $token_types[ (string) $type ] ?? 0 ) + 1;

					// Exercise every public token view while the token is current.
					$processor->get_normalized_token();
					$processor->get_token_value();
					$processor->get_token_unit();
					$processor->get_token_type_flag();
				}

				$check( strlen( $style ) === $expected_start, 'token-range-tail-gap', array( 'expectedEnd' => strlen( $style ), 'actualEnd' => $expected_start ) );
				$check( $style === $processor->get_updated_css(), 'token-noop-not-identity' );
			}
		);

		$guard(
			'repeated-token-update',
			static function () use ( $style, $seed, $check, &$operations ): void {
				$processor = \WP_CSS_Token_Processor::create( $style );
				while ( $processor->next_token() ) {
					$type = $processor->get_token_type();
					if ( \WP_CSS_Token_Processor::TOKEN_URL !== $type && \WP_CSS_Token_Processor::TOKEN_STRING !== $type ) {
						continue;
					}

					$start  = \WP_CSS_Token_Processor::TOKEN_URL === $type
						? $processor->get_token_value_start()
						: $processor->get_token_start();
					$length = \WP_CSS_Token_Processor::TOKEN_URL === $type
						? $processor->get_token_value_length()
						: $processor->get_token_length();
					$final  = 'final-' . $seed . ' & value';
					$check( is_int( $start ) && is_int( $length ), 'repeated-token-update-range-missing' );
					if ( ! is_int( $start ) || ! is_int( $length ) ) {
						return;
					}

					$check( $processor->set_token_value( 'superseded-' . $seed ), 'repeated-token-first-update-refused' );
					$check( $processor->set_token_value( $final ), 'repeated-token-final-update-refused' );
					$expected = substr( $style, 0, $start ) . \WP_CSS_Builder::string( $final ) . substr( $style, $start + $length );
					$check( $expected === $processor->get_updated_css(), 'repeated-token-update-not-superseded' );
					$operations['repeatedToken.' . $type] = 1;
					break;
				}
			}
		);

		$snapshot = array();
		$guard(
			'traversal',
			static function () use ( $style, $case, $check, &$snapshot ): void {
				$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
				$check( null === $processor->get_property_name(), 'getter-property-valid-before-cursor' );
				$check( null === $processor->is_important(), 'getter-important-valid-before-cursor' );
				$check( $style === $processor->get_updated_style(), 'style-noop-before-parse-not-identity' );

				$snapshot = self::capture_processor( $processor, strlen( $style ) );
				$check( null === $processor->get_property_name(), 'getter-property-valid-after-exhaustion' );
				$check( null === $processor->is_important(), 'getter-important-valid-after-exhaustion' );
				$check( $style === $processor->get_updated_style(), 'style-noop-after-traversal-not-identity' );
				$check( $snapshot === self::capture_style( $style ), 'traversal-nondeterministic' );

				if ( null !== $case['expected'] ) {
					$check( $case['expected'] === $snapshot, 'structured-model-mismatch', array( 'expected' => $case['expected'], 'actual' => $snapshot ) );
				}
			}
		);

		if ( ! empty( $snapshot ) ) {
			$guard(
				'filtered-traversal',
				static function () use ( $style, $snapshot, $seed, $check ): void {
					$selected = $snapshot[ $seed % count( $snapshot ) ]['name'];
					$query    = 0 === strpos( $selected, '--' ) ? $selected : strtoupper( $selected );
					$expected = array_values(
						array_filter(
							$snapshot,
							static function ( array $item ) use ( $query ): bool {
								if ( 0 === strpos( $item['name'], '--' ) || 0 === strpos( $query, '--' ) ) {
									return $item['name'] === $query;
								}
								return 0 === strcasecmp( $item['name'], $query );
							}
						)
					);
					$actual    = array();
					$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
					$limit     = count( $snapshot ) + 2;
					while ( $processor->next_declaration( $query ) ) {
						$actual[] = array(
							'name'      => $processor->get_property_name(),
							'important' => $processor->is_important(),
						);
						if ( count( $actual ) > $limit ) {
							break;
						}
					}
					$check( $expected === $actual, 'filtered-traversal-mismatch', array( 'query' => $query, 'expected' => $expected, 'actual' => $actual ) );
				}
			);
		}

		if ( ! empty( $snapshot ) ) {
			$ordinal = $seed % count( $snapshot );
			$guard(
				'set-value',
				static function () use ( $style, $snapshot, $ordinal, $seed, $check, &$operations ): void {
					$processor  = \WP_HTML_Style_Attribute_Processor::create( $style );
					$positioned = self::position( $processor, $ordinal );
					$check( $positioned, 'set-value-position-failed', array( 'ordinal' => $ordinal ) );
					if ( ! $positioned ) {
						return;
					}

					$before    = $processor->get_updated_style();
					$important = 0 === $seed % 2;
					$success   = $processor->set_value( CaseGenerator::mutation_value( $seed ), $important );
					$operations['setValue.' . ( $success ? 'success' : 'refused' )] = 1;
					if ( ! $success ) {
						$check( $before === $processor->get_updated_style(), 'set-value-failure-not-atomic' );
						$check( $snapshot[ $ordinal ]['name'] === $processor->get_property_name(), 'set-value-failure-moved-cursor' );
						return;
					}

					$check( $snapshot[ $ordinal ]['name'] === $processor->get_property_name(), 'set-value-success-moved-cursor' );
					$check( $important === $processor->is_important(), 'set-value-cursor-priority-mismatch' );
					$updated          = $processor->get_updated_style();
					$expected         = $snapshot;
					$expected[ $ordinal ]['important'] = $important;
					$check( $expected === self::capture_style( $updated ), 'set-value-reparse-mismatch', array( 'expected' => $expected, 'actual' => self::capture_style( $updated ) ) );

					$check( $processor->set_value( CaseGenerator::mutation_value( $seed ), $important ), 'set-value-repeat-refused' );
					$check( $updated === $processor->get_updated_style(), 'set-value-not-idempotent' );
				}
			);

			$guard(
				'invalid-set-value',
				static function () use ( $style, $snapshot, $ordinal, $seed, $check ): void {
					$invalid_values = array( 'red; color: blue', "'bad\nstring'", 'var(--x', 'red !important', '/* comment only */' );
					$processor      = \WP_HTML_Style_Attribute_Processor::create( $style );
					if ( ! self::position( $processor, $ordinal ) ) {
						return;
					}
					$before    = $processor->get_updated_style();
					$name      = $processor->get_property_name();
					$important = $processor->is_important();
					$success   = $processor->set_value( $invalid_values[ $seed % count( $invalid_values ) ] );
					$check( ! $success, 'unsafe-set-value-accepted' );
					$check( $before === $processor->get_updated_style(), 'unsafe-set-value-not-atomic' );
					$check( $name === $processor->get_property_name() && $important === $processor->is_important(), 'unsafe-set-value-changed-cursor' );
				}
			);

			$guard(
				'set-important',
				static function () use ( $style, $snapshot, $ordinal, $check, &$operations ): void {
					$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
					if ( ! self::position( $processor, $ordinal ) ) {
						return;
					}
					$before  = $processor->get_updated_style();
					$wanted  = ! $snapshot[ $ordinal ]['important'];
					$success = $processor->set_important( $wanted );
					$operations['setImportant.' . ( $success ? 'success' : 'refused' )] = 1;
					if ( ! $success ) {
						$check( $before === $processor->get_updated_style(), 'set-important-failure-not-atomic' );
						return;
					}

					$expected = $snapshot;
					$expected[ $ordinal ]['important'] = $wanted;
					$updated = $processor->get_updated_style();
					$check( $wanted === $processor->is_important(), 'set-important-cursor-mismatch' );
					$check( $expected === self::capture_style( $updated ), 'set-important-reparse-mismatch', array( 'expected' => $expected, 'actual' => self::capture_style( $updated ) ) );
					$check( $processor->set_important( $wanted ), 'set-important-idempotent-call-refused' );
					$check( $updated === $processor->get_updated_style(), 'set-important-not-idempotent' );
				}
			);

			$guard(
				'remove',
				static function () use ( $style, $snapshot, $ordinal, $check, &$operations ): void {
					$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
					if ( ! self::position( $processor, $ordinal ) ) {
						return;
					}
					$before  = $processor->get_updated_style();
					$success = $processor->remove_declaration();
					$operations['remove.' . ( $success ? 'success' : 'refused' )] = 1;
					if ( ! $success ) {
						$check( $before === $processor->get_updated_style(), 'remove-failure-not-atomic' );
						return;
					}

					$expected = $snapshot;
					array_splice( $expected, $ordinal, 1 );
					$check( null === $processor->get_property_name() && null === $processor->is_important(), 'remove-left-valid-cursor' );
					$check( ! $processor->remove_declaration(), 'remove-repeat-succeeded' );
					$check( $expected === self::capture_style( $processor->get_updated_style() ), 'remove-reparse-mismatch', array( 'expected' => $expected, 'actual' => self::capture_style( $processor->get_updated_style() ) ) );
				}
			);

			$guard(
				'empty-set-value',
				static function () use ( $style, $snapshot, $ordinal, $check, &$operations ): void {
					$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
					if ( ! self::position( $processor, $ordinal ) ) {
						return;
					}
					$before  = $processor->get_updated_style();
					$success = $processor->set_value( '' );
					$operations['emptySetValue.' . ( $success ? 'success' : 'refused' )] = 1;
					if ( ! $success ) {
						$check( $before === $processor->get_updated_style(), 'empty-set-value-failure-not-atomic' );
						return;
					}
					$expected = $snapshot;
					array_splice( $expected, $ordinal, 1 );
					$check( $expected === self::capture_style( $processor->get_updated_style() ), 'empty-set-value-reparse-mismatch' );
				}
			);
		}

		if ( 'structured' === $case['bucket'] && ! empty( $snapshot ) ) {
			$ordinal = $seed % count( $snapshot );
			$guard(
				'mutation-sequence',
				static function () use ( $style, $snapshot, $ordinal, $seed, $check, &$operations ): void {
					$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
					if ( ! self::position( $processor, $ordinal ) ) {
						$check( false, 'mutation-sequence-position-failed', array( 'ordinal' => $ordinal ) );
						return;
					}

					$original_name = $processor->get_property_name();
					$important     = 0 === $seed % 2;
					if ( ! $processor->set_value( CaseGenerator::mutation_value( $seed ), $important ) ) {
						$check( false, 'mutation-sequence-set-value-refused' );
						return;
					}

					$important = ! $important;
					if ( ! $processor->set_important( $important ) ) {
						$check( false, 'mutation-sequence-set-important-refused' );
						return;
					}

					$appended_name = 'sequence-prop-' . ( $seed % 37 );
					if ( ! $processor->append_declaration( $appended_name, 'calc(1px + 2px)', true ) ) {
						$check( false, 'mutation-sequence-append-refused' );
						return;
					}

					$check( $original_name === $processor->get_property_name(), 'mutation-sequence-append-moved-cursor' );
					$check( $important === $processor->is_important(), 'mutation-sequence-append-changed-priority' );
					if ( ! $processor->set_value( 'var(--sequence, 2px)' ) ) {
						$check( false, 'mutation-sequence-second-set-value-refused' );
						return;
					}

					$expected                           = $snapshot;
					$expected[ $ordinal ]['important'] = $important;
					$expected[]                         = array( 'name' => $appended_name, 'important' => true );
					$check( $expected === self::capture_style( $processor->get_updated_style() ), 'mutation-sequence-reparse-mismatch' );

					if ( ! $processor->remove_declaration() ) {
						$check( false, 'mutation-sequence-remove-refused' );
						return;
					}
					array_splice( $expected, $ordinal, 1 );
					$check( $expected === self::capture_style( $processor->get_updated_style() ), 'mutation-sequence-remove-reparse-mismatch' );
					$check( null === $processor->get_property_name(), 'mutation-sequence-remove-left-valid-cursor' );
					$check( $processor->next_declaration(), 'mutation-sequence-next-after-remove-failed' );
					$check( $expected[ $ordinal ]['name'] === $processor->get_property_name(), 'mutation-sequence-next-after-remove-mismatch' );
					$operations['mutationSequence.completed'] = 1;
				}
			);
		}

		$guard(
			'off-cursor-mutations',
			static function () use ( $style, $check ): void {
				$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
				$before    = $processor->get_updated_style();
				$check( ! $processor->set_value( 'red' ), 'off-cursor-set-value-succeeded' );
				$check( ! $processor->set_important( true ), 'off-cursor-set-important-succeeded' );
				$check( ! $processor->remove_declaration(), 'off-cursor-remove-succeeded' );
				$check( $before === $processor->get_updated_style(), 'off-cursor-mutation-not-atomic' );
			}
		);

		$guard(
			'append',
			static function () use ( $style, $snapshot, $seed, $case, $check, &$operations ): void {
				$processor  = \WP_HTML_Style_Attribute_Processor::create( $style );
				$before     = $processor->get_updated_style();
				$name       = CaseGenerator::append_property( $seed );
				$important  = 0 === $seed % 2;
				$success    = $processor->append_declaration( $name, CaseGenerator::mutation_value( $seed ), $important );
				$operations['append.' . ( $success ? 'success' : 'refused' )] = 1;
				if ( ! $success ) {
					$check( $before === $processor->get_updated_style(), 'append-failure-not-atomic' );
					if ( null !== $case['expected'] ) {
						$check( false, 'valid-append-refused' );
					}
					return;
				}

				$expected   = $snapshot;
				$expected[] = array(
					'name'      => 0 === strpos( $name, '--' ) ? $name : strtolower( $name ),
					'important' => $important,
				);
				$actual = self::capture_style( $processor->get_updated_style() );
				$check( $expected === $actual, 'append-reparse-mismatch', array( 'expected' => $expected, 'actual' => $actual ) );
			}
		);

		$guard(
			'invalid-append',
			static function () use ( $style, $seed, $check ): void {
				$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
				$before    = $processor->get_updated_style();
				$check( ! $processor->append_declaration( 'bad:name', CaseGenerator::mutation_value( $seed ) ), 'unsafe-append-property-accepted' );
				$check( $before === $processor->get_updated_style(), 'unsafe-append-property-not-atomic' );
				$check( ! $processor->append_declaration( 'fuzz-prop', 'red; injected: yes' ), 'unsafe-append-value-accepted' );
				$check( $before === $processor->get_updated_style(), 'unsafe-append-value-not-atomic' );
			}
		);

		$guard(
			'exhausted-cursor-append',
			static function () use ( $style, $snapshot, $seed, $case, $check, &$operations ): void {
				$processor = \WP_HTML_Style_Attribute_Processor::create( $style );
				while ( $processor->next_declaration() ) {
				}
				$name    = 'exhausted-fuzz-' . ( $seed % 31 );
				$success = $processor->append_declaration( $name, '1px' );
				$operations['exhaustedAppend.' . ( $success ? 'success' : 'refused' )] = 1;
				if ( ! $success ) {
					if ( null !== $case['expected'] ) {
						$check( false, 'valid-exhausted-append-refused' );
					}
					return;
				}
				$check( null === $processor->get_property_name(), 'exhausted-append-revalidated-cursor' );
				$check( $processor->next_declaration(), 'exhausted-append-not-reachable' );
				$check( $name === $processor->get_property_name(), 'exhausted-append-reached-wrong-declaration' );
				$expected   = $snapshot;
				$expected[] = array( 'name' => $name, 'important' => false );
				$check( $expected === self::capture_style( $processor->get_updated_style() ), 'exhausted-append-reparse-mismatch' );
			}
		);

		$guard(
			'builder-string',
			static function () use ( $seed, $check ): void {
				$payload   = substr( hash( 'sha256', 'builder:' . $seed, true ), 0, 24 ) . "\0\r\n\f";
				$css       = \WP_CSS_Builder::string( $payload );
				$processor = \WP_CSS_Token_Processor::create( $css );
				$check( null !== $processor && $processor->next_token(), 'builder-string-not-tokenized' );
				if ( null === $processor || null === $processor->get_token_type() ) {
					return;
				}
				$expected = wp_scrub_utf8( $payload );
				$expected = str_replace( array( "\r\n", "\r", "\f", "\0" ), array( "\n", "\n", "\n", "\u{FFFD}" ), $expected );
				$check( \WP_CSS_Token_Processor::TOKEN_STRING === $processor->get_token_type(), 'builder-string-wrong-token-type' );
				$check( $expected === $processor->get_token_value(), 'builder-string-roundtrip-mismatch' );
				$check( ! $processor->next_token(), 'builder-string-emitted-extra-token' );
			}
		);

		return array(
			'seed'       => $seed,
			'bucket'     => $case['bucket'],
			'style'      => $style,
			'snapshot'   => $snapshot,
			'failures'   => $failures,
			'tokenTypes' => $token_types,
			'operations' => $operations,
		);
	}

	public static function run_batch( array $options ): array {
		$start_seed   = option_int( $options, 'start-seed', 1 );
		$count        = max( 1, option_int( $options, 'count', 100 ) );
		$failures_out = option_string( $options, 'failures-out', null );
		$max_failures = max( 1, option_int( $options, 'max-failures', 100 ) );
		$summary      = array(
			'kind'       => 'css-declaration-fuzz-batch-summary',
			'cases'      => 0,
			'buckets'    => array(),
			'failures'   => 0,
			'signatures' => array(),
			'tokenTypes' => array(),
			'operations' => array(),
		);

		for ( $seed = $start_seed; $seed < $start_seed + $count; $seed++ ) {
			$result = self::run_case( $seed );
			++$summary['cases'];
			$summary['buckets'][ $result['bucket'] ] = ( $summary['buckets'][ $result['bucket'] ] ?? 0 ) + 1;
			foreach ( $result['tokenTypes'] as $type => $type_count ) {
				$summary['tokenTypes'][ $type ] = ( $summary['tokenTypes'][ $type ] ?? 0 ) + $type_count;
			}
			foreach ( $result['operations'] as $operation => $operation_count ) {
				$summary['operations'][ $operation ] = ( $summary['operations'][ $operation ] ?? 0 ) + $operation_count;
			}

			foreach ( $result['failures'] as $failure ) {
				++$summary['failures'];
				$signature = $failure['signature'];
				$summary['signatures'][ $signature ] = ( $summary['signatures'][ $signature ] ?? 0 ) + 1;
				if ( null !== $failures_out ) {
					append_ndjson(
						$failures_out,
						array(
							'kind'           => 'css-declaration-fuzz-failure',
							'seed'           => $seed,
							'bucket'         => $result['bucket'],
							'invariant'      => $failure['invariant'],
							'signature'      => $signature,
							'detail'         => $failure['detail'],
							'styleBase64'    => base64_encode( $result['style'] ),
							'stylePrintable' => printable_bytes( $result['style'] ),
						)
					);
				}
			}

			if ( $summary['failures'] >= $max_failures ) {
				break;
			}
		}

		return $summary;
	}

	/** @return array<int,array{name:string,important:bool}> */
	public static function capture_style( string $style ): array {
		Bootstrap::load();
		return self::capture_processor( \WP_HTML_Style_Attribute_Processor::create( $style ), strlen( $style ) );
	}

	/** @return array<int,array{name:string,important:bool}> */
	private static function capture_processor( \WP_HTML_Style_Attribute_Processor $processor, int $input_length ): array {
		$declarations = array();
		$limit        = max( 64, $input_length * 2 + 64 );
		while ( $processor->next_declaration() ) {
			$declarations[] = array(
				'name'      => (string) $processor->get_property_name(),
				'important' => (bool) $processor->is_important(),
			);
			if ( count( $declarations ) > $limit ) {
				throw new \RuntimeException( 'Declaration traversal did not make forward progress.' );
			}
		}
		return $declarations;
	}

	private static function position( \WP_HTML_Style_Attribute_Processor $processor, int $ordinal ): bool {
		for ( $i = 0; $i <= $ordinal; $i++ ) {
			if ( ! $processor->next_declaration() ) {
				return false;
			}
		}
		return true;
	}

	public static function describe_throwable( \Throwable $e ): array {
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
		);
	}
}
