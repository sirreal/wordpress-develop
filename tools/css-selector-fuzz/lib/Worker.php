<?php
namespace CssSelectorFuzz;

/**
 * Executes fuzz cases and checks invariants.
 *
 * Invariants checked per case:
 *
 *  - model-desync:            the parsed document disagrees with the generated
 *                             model ( oracle soundness check ).
 *  - parse-error:             from_selectors() raised an error/exception.
 *  - parse-expectation:       parse success/failure does not match the
 *                             generated bucket's expectation.
 *  - compound-implies-complex: a selector parsed in the compound grammar but
 *                             not in the complex grammar ( a superset ).
 *  - parse-determinism:       parsing the same input twice gave different results.
 *  - ast-shape:               parsed selector objects violate documented shape.
 *  - ast-mismatch:            parsed AST differs from the intended generated AST.
 *  - ast-cross-grammar:       compound-list and complex-list ASTs disagree.
 *  - match-error:             select()/matches() raised an error/exception.
 *  - match-mismatch-html:     WP_HTML_Processor::select() match set differs
 *                             from the reference matcher.
 *  - match-mismatch-tag:      WP_HTML_Tag_Processor::select() match set
 *                             differs from the reference matcher.
 *  - doing-it-wrong-unexpected: _doing_it_wrong fired for a selector that parsed.
 *  - doing-it-wrong-missing:  _doing_it_wrong did not fire ( or fired the wrong
 *                             number of times ) for an unparseable selector.
 *  - select-on-null:          select() returned true for an unparseable selector.
 *  - processor-error:         the processor entered an error/unsupported state.
 *  - case-determinism:        running the full case twice gave different digests.
 */
class Worker {

	const SELECT_ITERATION_LIMIT = 10000;

	/**
	 * Runs a single fuzz case.
	 *
	 * @return array{
	 *     seed: int,
	 *     bucket: string,
	 *     digest: string,
	 *     failures: array,
	 *     selector: string,
	 *     html: string,
	 * }
	 */
	public static function run_case( int $seed ): array {
		Bootstrap::load();

		$prng     = new Prng( (string) $seed, 'css-selector-fuzz-case' );
		$document = DocumentGenerator::generate( $prng->fork( 'document' ) );
		$selector = SelectorGenerator::generate( $prng->fork( 'selector' ), $document['pools'] );

		$failures = array();
		$record   = static function ( string $invariant, array $detail ) use ( &$failures ) {
			$failures[] = array(
				'invariant' => $invariant,
				'detail'    => $detail,
			);
		};

		self::check_document_model( $document, $record );

		$selector_string = $selector['selector'];

		// --- Parse phase -------------------------------------------------

		list( $compound_list, $compound_error ) = self::guard(
			static function () use ( $selector_string ) {
				return \WP_CSS_Compound_Selector_List::from_selectors( $selector_string );
			}
		);
		list( $complex_list, $complex_error )   = self::guard(
			static function () use ( $selector_string ) {
				return \WP_CSS_Complex_Selector_List::from_selectors( $selector_string );
			}
		);

		if ( null !== $compound_error ) {
			$record( 'parse-error', array( 'grammar' => 'compound', 'error' => self::describe_throwable( $compound_error ) ) );
		}
		if ( null !== $complex_error ) {
			$record( 'parse-error', array( 'grammar' => 'complex', 'error' => self::describe_throwable( $complex_error ) ) );
		}

		if ( null === $compound_error && null !== $selector['expectCompound'] && $selector['expectCompound'] !== ( null !== $compound_list ) ) {
			$record(
				'parse-expectation',
				array(
					'grammar'  => 'compound',
					'expected' => $selector['expectCompound'] ? 'parse' : 'null',
					'actual'   => null !== $compound_list ? 'parse' : 'null',
				)
			);
		}
		if ( null === $complex_error && null !== $selector['expectComplex'] && $selector['expectComplex'] !== ( null !== $complex_list ) ) {
			$record(
				'parse-expectation',
				array(
					'grammar'  => 'complex',
					'expected' => $selector['expectComplex'] ? 'parse' : 'null',
					'actual'   => null !== $complex_list ? 'parse' : 'null',
				)
			);
		}

		if ( null !== $compound_list && null === $complex_list && null === $complex_error ) {
			$record( 'compound-implies-complex', array() );
		}

		// Parse determinism: a second parse must agree with the first.
		list( $compound_again, ) = self::guard(
			static function () use ( $selector_string ) {
				return \WP_CSS_Compound_Selector_List::from_selectors( $selector_string );
			}
		);
		list( $complex_again, )  = self::guard(
			static function () use ( $selector_string ) {
				return \WP_CSS_Complex_Selector_List::from_selectors( $selector_string );
			}
		);
		if ( ( null === $compound_list ) !== ( null === $compound_again ) || ( null === $complex_list ) !== ( null === $complex_again ) ) {
			$record( 'parse-determinism', array( 'note' => 'null-ness changed between identical parses' ) );
		}

		// --- AST extraction ----------------------------------------------

		$compound_ast = null;
		$complex_ast  = null;

		if ( null !== $compound_list ) {
			list( $compound_ast, $shape_error ) = self::guard(
				static function () use ( $compound_list ) {
					return AstExtractor::from_compound_list( $compound_list );
				}
			);
			if ( null !== $shape_error ) {
				$record( 'ast-shape', array( 'grammar' => 'compound', 'error' => self::describe_throwable( $shape_error ) ) );
			}
		}
		if ( null !== $complex_list ) {
			list( $complex_ast, $shape_error ) = self::guard(
				static function () use ( $complex_list ) {
					return AstExtractor::from_complex_list( $complex_list );
				}
			);
			if ( null !== $shape_error ) {
				$record( 'ast-shape', array( 'grammar' => 'complex', 'error' => self::describe_throwable( $shape_error ) ) );
			}
		}

		if ( null !== $compound_ast && null !== $complex_ast && $compound_ast !== $complex_ast ) {
			$record(
				'ast-cross-grammar',
				array(
					'compoundAst' => $compound_ast,
					'complexAst'  => $complex_ast,
				)
			);
		}

		if ( null !== $selector['ast'] && null !== $complex_ast && $selector['ast'] !== $complex_ast ) {
			$record(
				'ast-mismatch',
				array(
					'generatedAst' => $selector['ast'],
					'parsedAst'    => $complex_ast,
				)
			);
		}

		// --- Match phase ---------------------------------------------------

		if ( null !== $complex_ast ) {
			$expected = ReferenceMatcher::expected_html_processor_matches( $complex_ast, $document['model'], $document['quirks'] );
			self::check_select_matches( 'html', $selector_string, $document, $expected, $record );
		} elseif ( null === $complex_list && null === $complex_error ) {
			self::check_select_rejection( 'html', $selector_string, $document, $record );
		}

		if ( null !== $compound_ast ) {
			$expected = ReferenceMatcher::expected_tag_processor_matches( $compound_ast, $document['model'] );
			self::check_select_matches( 'tag', $selector_string, $document, $expected, $record );
		} elseif ( null === $compound_list && null === $compound_error ) {
			self::check_select_rejection( 'tag', $selector_string, $document, $record );
		}

		$digest = sha1(
			json_encode_safe(
				array(
					$selector_string,
					$document['html'],
					null !== $compound_list,
					null !== $complex_list,
					$compound_ast,
					$complex_ast,
					array_map(
						static function ( $failure ) {
							return $failure['invariant'];
						},
						$failures
					),
				)
			)
		);

		return array(
			'seed'     => $seed,
			'bucket'   => $selector['bucket'],
			'digest'   => $digest,
			'failures' => $failures,
			'selector' => $selector_string,
			'html'     => $document['html'],
		);
	}

	/**
	 * Verifies that both processors see exactly the modeled element list —
	 * this guards the oracle itself against renderer/model drift.
	 */
	private static function check_document_model( array $document, callable $record ): void {
		$expected = array();
		foreach ( DocumentGenerator::flatten_with_ancestors( $document['model'] ) as $pair ) {
			list( $element, $ancestors ) = $pair;
			$expected[]                  = array(
				strtoupper( ascii_strtolower( $element['tag'] ) ),
				$element['fid'],
				count( $ancestors ) + 1,
			);
		}

		list( $actual, $error ) = self::guard(
			static function () use ( $document ) {
				$processor = \WP_HTML_Processor::create_full_parser( $document['html'] );
				$out       = array();
				while ( $processor->next_tag() ) {
					$fid   = $processor->get_attribute( 'data-fid' );
					$out[] = array(
						(string) $processor->get_tag(),
						is_string( $fid ) ? $fid : '(missing)',
						count( $processor->get_breadcrumbs() ),
					);
				}
				if ( null !== $processor->get_last_error() ) {
					throw new \RuntimeException( 'Processor error: ' . $processor->get_last_error() );
				}
				return $out;
			}
		);

		if ( null !== $error ) {
			$record( 'model-desync', array( 'processor' => 'html', 'error' => self::describe_throwable( $error ) ) );
			return;
		}

		if ( $actual !== $expected ) {
			$record(
				'model-desync',
				array(
					'processor' => 'html',
					'expected'  => $expected,
					'actual'    => $actual,
				)
			);
		}

		// The tag processor must see the same elements ( without breadcrumbs ).
		$expected_tags = array();
		foreach ( $expected as $row ) {
			$expected_tags[] = array( $row[0], $row[1] );
		}

		list( $actual_tags, $tag_error ) = self::guard(
			static function () use ( $document ) {
				$processor = new \WP_HTML_Tag_Processor( $document['html'] );
				$out       = array();
				while ( $processor->next_tag() ) {
					$fid   = $processor->get_attribute( 'data-fid' );
					$out[] = array(
						(string) $processor->get_tag(),
						is_string( $fid ) ? $fid : '(missing)',
					);
				}
				return $out;
			}
		);

		if ( null !== $tag_error ) {
			$record( 'model-desync', array( 'processor' => 'tag', 'error' => self::describe_throwable( $tag_error ) ) );
			return;
		}

		if ( $actual_tags !== $expected_tags ) {
			$record(
				'model-desync',
				array(
					'processor' => 'tag',
					'expected'  => $expected_tags,
					'actual'    => $actual_tags,
				)
			);
		}
	}

	/**
	 * Runs a select() loop on a parseable selector and compares the match set
	 * against the reference matcher.
	 *
	 * @param string $target 'html' or 'tag'.
	 */
	private static function check_select_matches( string $target, string $selector_string, array $document, array $expected, callable $record ): void {
		Bootstrap::reset_doing_it_wrong();

		list( $actual, $error ) = self::guard(
			static function () use ( $target, $selector_string, $document ) {
				$processor = 'html' === $target
					? \WP_HTML_Processor::create_full_parser( $document['html'] )
					: new \WP_HTML_Tag_Processor( $document['html'] );

				$matches    = array();
				$iterations = 0;
				while ( $processor->select( $selector_string ) ) {
					$fid       = $processor->get_attribute( 'data-fid' );
					$matches[] = is_string( $fid ) ? $fid : '(missing-fid:' . $processor->get_tag() . ')';
					if ( ++$iterations > self::SELECT_ITERATION_LIMIT ) {
						throw new \RuntimeException( 'select() did not terminate within the iteration limit.' );
					}
				}

				if ( $processor instanceof \WP_HTML_Processor ) {
					if ( null !== $processor->get_last_error() ) {
						throw new \RuntimeException( 'Processor error state: ' . $processor->get_last_error() );
					}
					if ( null !== $processor->get_unsupported_exception() ) {
						throw new \RuntimeException( 'Processor unsupported state: ' . $processor->get_unsupported_exception()->getMessage() );
					}
				}

				return $matches;
			}
		);

		if ( null !== $error ) {
			$record(
				'match-error',
				array(
					'target' => $target,
					'error'  => self::describe_throwable( $error ),
				)
			);
			return;
		}

		$doing_it_wrong = Bootstrap::doing_it_wrong_calls();
		if ( array() !== $doing_it_wrong ) {
			$record(
				'doing-it-wrong-unexpected',
				array(
					'target' => $target,
					'calls'  => $doing_it_wrong,
				)
			);
		}

		if ( $actual !== $expected ) {
			$record(
				'match-mismatch-' . $target,
				array(
					'expected' => $expected,
					'actual'   => $actual,
				)
			);
		}
	}

	/**
	 * For unparseable selectors: select() must return false, leave the
	 * processor usable, and report misuse exactly once per call.
	 */
	private static function check_select_rejection( string $target, string $selector_string, array $document, callable $record ): void {
		Bootstrap::reset_doing_it_wrong();

		list( $results, $error ) = self::guard(
			static function () use ( $target, $selector_string, $document ) {
				$processor = 'html' === $target
					? \WP_HTML_Processor::create_full_parser( $document['html'] )
					: new \WP_HTML_Tag_Processor( $document['html'] );

				// Two calls: the second exercises the parse cache.
				return array( $processor->select( $selector_string ), $processor->select( $selector_string ) );
			}
		);

		if ( null !== $error ) {
			$record(
				'match-error',
				array(
					'target'   => $target,
					'rejected' => true,
					'error'    => self::describe_throwable( $error ),
				)
			);
			return;
		}

		if ( array( false, false ) !== $results ) {
			$record(
				'select-on-null',
				array(
					'target'  => $target,
					'results' => $results,
				)
			);
		}

		$doing_it_wrong = Bootstrap::doing_it_wrong_calls();
		if ( 2 !== count( $doing_it_wrong ) ) {
			$record(
				'doing-it-wrong-missing',
				array(
					'target'        => $target,
					'expectedCalls' => 2,
					'calls'         => $doing_it_wrong,
				)
			);
		}
	}

	/*
	 * -------------
	 * Batch running
	 * -------------
	 */

	/**
	 * Runs a batch of sequential seeds.
	 *
	 * @return array Summary.
	 */
	public static function run_batch( array $options ): array {
		Bootstrap::load();

		$start_seed        = option_int( $options, 'start-seed', 1 );
		$count             = option_int( $options, 'count', 100 );
		$failures_out      = option_string( $options, 'failures-out', null );
		$progress_file     = option_string( $options, 'progress-file', null );
		$determinism_every = option_int( $options, 'determinism-every', 16 );
		$max_failures      = option_int( $options, 'max-failures', 200 );

		$started_at  = microtime( true );
		$failures    = 0;
		$buckets     = array();
		$signatures  = array();
		$last_seed   = null;
		$stop_reason = 'completed';

		for ( $seed = $start_seed; $seed < $start_seed + $count; $seed++ ) {
			if ( $max_failures > 0 && $failures >= $max_failures ) {
				$stop_reason = 'max-failures';
				break;
			}
			if ( null !== $progress_file ) {
				file_put_contents( $progress_file, (string) $seed );
			}

			$result = self::run_case( $seed );

			if ( $determinism_every > 0 && 0 === $seed % $determinism_every ) {
				$repeat = self::run_case( $seed );
				if ( $repeat['digest'] !== $result['digest'] ) {
					$result['failures'][] = array(
						'invariant' => 'case-determinism',
						'detail'    => array(
							'firstDigest'  => $result['digest'],
							'secondDigest' => $repeat['digest'],
						),
					);
				}
			}

			$buckets[ $result['bucket'] ] = ( $buckets[ $result['bucket'] ] ?? 0 ) + 1;
			$last_seed                    = $seed;

			foreach ( $result['failures'] as $failure ) {
				++$failures;
				$signature                = self::signature( $failure );
				$signatures[ $signature ] = ( $signatures[ $signature ] ?? 0 ) + 1;

				$entry = array(
					'kind'            => 'css-selector-fuzz-failure',
					'seed'            => $result['seed'],
					'bucket'          => $result['bucket'],
					'invariant'       => $failure['invariant'],
					'signature'       => $signature,
					'selector'        => printable_bytes( $result['selector'] ),
					'selectorBase64'  => base64_encode( $result['selector'] ),
					'htmlBase64'      => base64_encode( $result['html'] ),
					'detail'          => $failure['detail'],
				);
				if ( null !== $failures_out ) {
					append_ndjson( $failures_out, $entry );
				} else {
					fwrite( STDERR, json_encode_safe( $entry ) . "\n" );
				}
			}
		}

		return array(
			'kind'        => 'css-selector-fuzz-batch-summary',
			'startSeed'   => $start_seed,
			'count'       => $count,
			'lastSeed'    => $last_seed,
			'failures'    => $failures,
			'buckets'     => $buckets,
			'signatures'  => $signatures,
			'stopReason'  => $stop_reason,
			'durationMs'  => (int) round( 1000 * ( microtime( true ) - $started_at ) ),
		);
	}

	/** Stable identity for de-duplicating equivalent failures. */
	private static function signature( array $failure ): string {
		$parts = array( $failure['invariant'] );
		if ( isset( $failure['detail']['grammar'] ) ) {
			$parts[] = $failure['detail']['grammar'];
		}
		if ( isset( $failure['detail']['target'] ) ) {
			$parts[] = $failure['detail']['target'];
		}
		if ( isset( $failure['detail']['error']['class'] ) ) {
			$parts[] = $failure['detail']['error']['class'];
			$parts[] = preg_replace( '/[0-9]+/', 'N', (string) ( $failure['detail']['error']['message'] ?? '' ) );
		}
		return substr( sha1( implode( '|', $parts ) ), 0, 12 ) . ':' . $failure['invariant'];
	}

	/*
	 * -------
	 * Helpers
	 * -------
	 */

	/**
	 * Calls $fn with PHP warnings/notices converted to exceptions.
	 *
	 * @return array{0: mixed, 1: \Throwable|null}
	 */
	private static function guard( callable $fn ): array {
		set_error_handler(
			static function ( $severity, $message, $file, $line ) {
				if ( E_DEPRECATED === $severity || E_USER_DEPRECATED === $severity ) {
					return true;
				}
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			return array( $fn(), null );
		} catch ( \Throwable $e ) {
			return array( null, $e );
		} finally {
			restore_error_handler();
		}
	}

	public static function describe_throwable( \Throwable $e ): array {
		$root = repo_root() . DIRECTORY_SEPARATOR;
		return array(
			'class'   => get_class( $e ),
			'message' => $e->getMessage(),
			'at'      => str_replace( $root, '', $e->getFile() ) . ':' . $e->getLine(),
			'trace'   => array_slice(
				array_map(
					static function ( $frame ) use ( $root ) {
						$location = isset( $frame['file'] )
							? str_replace( $root, '', $frame['file'] ) . ':' . ( $frame['line'] ?? '?' )
							: '[internal]';
						$callable = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' );
						return $location . ' ' . $callable;
					},
					$e->getTrace()
				),
				0,
				6
			),
		);
	}
}
