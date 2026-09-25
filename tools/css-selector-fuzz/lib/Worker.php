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
 *  - doing-it-wrong-unexpected: the _doing_it_wrong calls during matching did
 *                             not equal the expected set ( exactly one scrub
 *                             notice for an invalid-UTF-8 selector, none
 *                             otherwise ).
 *  - doing-it-wrong-missing:  the _doing_it_wrong calls for an unparseable
 *                             selector did not equal the expected set ( one
 *                             select() notice per call, plus one leading
 *                             scrub notice when the selector is invalid
 *                             UTF-8 ).
 *  - select-on-null:          select() returned true for an unparseable selector.
 *  - processor-error:         the processor entered an error/unsupported state.
 *  - case-determinism:        running the full case twice gave different digests.
 *  - metamorphic-parse:       a meaning-preserving transform of a parseable
 *                             selector no longer parses.
 *  - metamorphic-ast:         an AST-preserving transform parsed to a
 *                             different AST.
 *  - metamorphic-mismatch:    a meaning-preserving transform selected a
 *                             different element set than the original.
 *  - metamorphic-error:       parsing/matching a transformed selector raised.
 *  - path-expectation:        a path-directed selector's guaranteed
 *                             (non-)membership does not hold in the reference
 *                             matcher ( generator/oracle defect ).
 */
class Worker {

	const SELECT_ITERATION_LIMIT = 10000;

	/** Metamorphic PRNG draws tried per pair in run_pair (minimizer). */
	const PAIR_METAMORPH_DRAWS = 12;

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
	 *     lexbor: string,
	 *     matchStats: array,
	 * }
	 */
	public static function run_case( int $seed ): array {
		Bootstrap::load();

		$prng        = new Prng( (string) $seed, 'css-selector-fuzz-case' );
		$is_wild     = $prng->chance( 30 );
		$is_fragment = ! $is_wild && $prng->chance( 20 );

		$failures = array();
		$record   = static function ( string $invariant, array $detail ) use ( &$failures ) {
			$failures[] = array(
				'invariant' => $invariant,
				'detail'    => $detail,
			);
		};
		$match_stats = array();

		/*
		 * The processor's own parse is the matching oracle's ground truth.
		 * For safe (model-built) documents the model must agree with the
		 * capture — that soundness check is what lets the capture be trusted
		 * on wild documents, where no model exists.
		 *
		 * Wild documents that hit one of the processor's unsupported
		 * constructs (it bails on foster parenting, complex adoption-agency
		 * runs, …) are deterministically regenerated a bounded number of
		 * times so nearly every wild case carries a usable ground truth.
		 */
		$document      = null;
		$capture       = null;
		$capture_error = null;
		$attempts      = $is_wild ? 8 : 1;
		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			if ( $is_wild ) {
				$document = WildDocumentGenerator::generate( $prng->fork( "wild-document:{$attempt}" ) );
			} elseif ( $is_fragment ) {
				$document = DocumentGenerator::generate_fragment( $prng->fork( 'fragment' ) );
			} else {
				$document = DocumentGenerator::generate( $prng->fork( 'document' ) );
			}

			$context = ( $document['fragment'] ?? false ) ? $document['context'] : null;
			list( $capture, $capture_error ) = self::guard(
				static function () use ( $document, $context ) {
					return TreeCapture::capture( $document['html'], $context );
				}
			);

			if ( null === $capture_error && null === $capture['error'] ) {
				break;
			}
		}

		$rows     = null;
		$tag_rows = null;
		$quirks   = false;

		if ( null !== $capture_error ) {
			$record( 'model-desync', array( 'phase' => 'capture', 'error' => self::describe_throwable( $capture_error ) ) );
		} elseif ( null !== $capture['error'] ) {
			if ( ! $is_wild ) {
				$record( 'model-desync', array( 'phase' => 'capture', 'error' => $capture['error'] ) );
			}
			// Wild markup the processor cannot fully visit is skipped:
			// parsing invariants still run, matching has no ground truth.
		} else {
			$rows     = $capture['htmlRows'];
			$tag_rows = $capture['tagRows'];
			$quirks   = $capture['quirks'];

			if ( $is_fragment ) {
				self::check_fragment_capture_against_model( $document, $capture, $record );
			} elseif ( ! $is_wild ) {
				self::check_capture_against_model( $document, $capture, $record );
			}
		}

		$path_rows = null;
		if ( null !== $rows ) {
			$path_rows = array();
			foreach ( $rows as $row ) {
				if ( 0 !== strpos( $row['fid'], '(missing-fid:' ) ) {
					$path_rows[] = $row;
				}
			}
		}

		$selector = SelectorGenerator::generate( $prng->fork( 'selector' ), $document['pools'], $path_rows );

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

		$html_matches = null;
		// 'n/a' = the lexbor differential does not apply to this case
		// ( unparseable selector, fragment, no captured tree ). Distinct from
		// 'unavailable', which check_lexbor_differential reports only when the
		// harness itself is missing or died — so a silently-dropped third
		// oracle shows up in the per-batch tally instead of hiding in 'off'.
		$lexbor_state = 'n/a';
		if ( null !== $complex_ast && null !== $rows ) {
			$expected = ReferenceMatcher::expected_html_matches_rows( $complex_ast, $rows, $quirks );

			/*
			 * Path-directed selectors are guaranteed by construction to match
			 * ( or, for near-misses, not to match ) a specific element. The
			 * reference matcher disagreeing means the generator or the
			 * reference matcher itself is wrong — a fuzzer-side defect.
			 */
			$must_match     = $selector['mustMatchFid'] ?? null;
			$must_not_match = $selector['mustNotMatchFid'] ?? null;
			if ( null !== $must_match && ! in_array( $must_match, $expected, true ) ) {
				$record(
					'path-expectation',
					array(
						'expectation' => 'must-match',
						'fid'         => $must_match,
						'expected'    => $expected,
					)
				);
			}
			if ( null !== $must_not_match && in_array( $must_not_match, $expected, true ) ) {
				$record(
					'path-expectation',
					array(
						'expectation' => 'must-not-match',
						'fid'         => $must_not_match,
						'expected'    => $expected,
					)
				);
			}

			$html_matches = self::check_select_matches( 'html', $selector_string, $document, $expected, $record );
			if ( null !== $html_matches ) {
				self::note_match_assertion( $match_stats, 'html', $expected, $html_matches );
			}

			// lexbor parses full documents only; fragments skip it.
			if ( ! ( $document['fragment'] ?? false ) ) {
				$lexbor_state = self::check_lexbor_differential( $complex_ast, $selector_string, $document, $rows, $quirks, $expected, $record );
			}
		} elseif ( null === $complex_list && null === $complex_error ) {
			self::check_select_rejection( 'html', $selector_string, $document, $record );
		}

		if ( null !== $compound_ast && null !== $tag_rows ) {
			$expected = ReferenceMatcher::expected_tag_matches_rows( $compound_ast, $tag_rows );
			$tag_matches = self::check_select_matches( 'tag', $selector_string, $document, $expected, $record );
			if ( null !== $tag_matches ) {
				self::note_match_assertion( $match_stats, 'tag', $expected, $tag_matches );
			}
		} elseif ( null === $compound_list && null === $compound_error ) {
			self::check_select_rejection( 'tag', $selector_string, $document, $record );
		}

		// --- Metamorphic phase ----------------------------------------------
		// Oracle-free relations: meaning-preserving transforms of the selector
		// must select exactly the same elements. Run only on otherwise-clean
		// cases so a single root cause does not multiply into noise.

		if ( null !== $complex_ast && null !== $html_matches && array() === $failures ) {
			self::check_metamorphic( $complex_ast, $html_matches, $document, $prng->fork( 'metamorph' ), $record );
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

		$signatures = array();
		foreach ( $failures as $failure ) {
			$signatures[] = self::signature( $failure );
		}

		return array(
			'seed'       => $seed,
			'bucket'     => $selector['bucket'],
			'digest'     => $digest,
			'failures'   => $failures,
			'signatures' => array_values( array_unique( $signatures ) ),
			'selector'   => $selector_string,
			'html'       => $document['html'],
			'lexbor'     => $lexbor_state,
			'matchStats' => $match_stats,
		);
	}

	/**
	 * Runs the SELF-CONTAINED invariants on an explicit ( selector, html )
	 * pair — no generated model, intended AST, or parse expectation. This is
	 * what the minimizer drives: every checked property is computable from
	 * the pair alone ( WP select() vs the reference matcher over WP's own
	 * parsed AST and the captured tree; metamorphic relations; the lexbor
	 * differential; parse/shape/cross-grammar invariants; rejection
	 * bookkeeping for unparseable selectors ).
	 *
	 * Bug 1 surfaces here as metamorphic-ast, Bug 2 as match-mismatch-*,
	 * Bug 3 as metamorphic-parse — so all three known bugs are minimizable
	 * without the generator.
	 *
	 * @return array{
	 *     failures: array,
	 *     signatures: string[],
	 * }
	 */
	public static function run_pair( string $selector_string, string $html, ?string $target = null ): array {
		Bootstrap::load();

		$failures = array();
		$record   = static function ( string $invariant, array $detail ) use ( &$failures ) {
			$failures[] = array(
				'invariant' => $invariant,
				'detail'    => $detail,
			);
		};

		// When the minimizer fixes a target signature, the metamorphic loop
		// ( the only expensive, multi-draw stage ) is only worth running if
		// the target is itself a metamorphic signature.
		$target_invariant     = null === $target ? null : substr( strrchr( $target, ':' ), 1 );
		$target_is_metamorph  = null !== $target_invariant && 0 === strpos( $target_invariant, 'metamorphic' );
		$has_target_signature = static function () use ( &$failures, $target ) {
			if ( null === $target ) {
				return false;
			}
			foreach ( $failures as $failure ) {
				if ( self::signature( $failure ) === $target ) {
					return true;
				}
			}
			return false;
		};

		list( $capture, $capture_error ) = self::guard(
			static function () use ( $html ) {
				return TreeCapture::capture( $html );
			}
		);

		$rows     = null;
		$tag_rows = null;
		$quirks   = false;
		if ( null === $capture_error && null === $capture['error'] ) {
			$rows     = $capture['htmlRows'];
			$tag_rows = $capture['tagRows'];
			$quirks   = $capture['quirks'];
		}

		$document = array( 'html' => $html );

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
		if ( null !== $compound_list && null === $complex_list && null === $complex_error ) {
			$record( 'compound-implies-complex', array() );
		}

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
			$record( 'ast-cross-grammar', array( 'compoundAst' => $compound_ast, 'complexAst' => $complex_ast ) );
		}

		$html_matches = null;
		if ( null !== $complex_ast && null !== $rows ) {
			$expected     = ReferenceMatcher::expected_html_matches_rows( $complex_ast, $rows, $quirks );
			$html_matches = self::check_select_matches( 'html', $selector_string, $document, $expected, $record );
			self::check_lexbor_differential( $complex_ast, $selector_string, $document, $rows, $quirks, $expected, $record );
		} elseif ( null === $complex_list && null === $complex_error && null !== $rows ) {
			self::check_select_rejection( 'html', $selector_string, $document, $record );
		}

		if ( null !== $compound_ast && null !== $tag_rows ) {
			$expected = ReferenceMatcher::expected_tag_matches_rows( $compound_ast, $tag_rows );
			self::check_select_matches( 'tag', $selector_string, $document, $expected, $record );
		} elseif ( null === $compound_list && null === $compound_error && null !== $tag_rows ) {
			self::check_select_rejection( 'tag', $selector_string, $document, $record );
		}

		$run_metamorph = ( null === $target || $target_is_metamorph )
			&& null !== $complex_ast && null !== $html_matches && array() === $failures;
		if ( $run_metamorph ) {
			/*
			 * Metamorphic transforms randomize escapes / case / order, so a
			 * transform-sensitive bug ( e.g. Bug 1 and Bug 3 ) only fires for
			 * some PRNG draws. run_case sees one draw; here several fixed
			 * draws are tried so minimization can reliably preserve such a
			 * signature regardless of which draw first exposed it. With a
			 * target fixed, stop at the first draw that reproduces it.
			 */
			for ( $i = 0; $i < self::PAIR_METAMORPH_DRAWS && array() === $failures; $i++ ) {
				// A FIXED draw seed ( not derived from the pair ) keeps the
				// test monotonic under shrinking: the same coin-flips apply to
				// whatever AST survives, so a smaller selector that still has
				// the bug reproduces the same transform signature.
				$metamorph_prng = new Prng( 'css-selector-fuzz-minimize', "metamorph:{$i}" );
				self::check_metamorphic( $complex_ast, $html_matches, $document, $metamorph_prng, $record );
				if ( $has_target_signature() ) {
					break;
				}
			}
		}

		$signatures = array();
		foreach ( $failures as $failure ) {
			$signatures[] = self::signature( $failure );
		}

		return array(
			'failures'   => $failures,
			'signatures' => array_values( array_unique( $signatures ) ),
		);
	}

	/**
	 * Fragment analogue of check_capture_against_model: the `<body>`-context
	 * fragment capture must equal the model rows built from the body-level
	 * children ( with the implicit HTML/BODY ancestors ).
	 */
	private static function check_fragment_capture_against_model( array $document, array $capture, callable $record ): void {
		$model_rows = DocumentGenerator::rows_from_fragment( $document['children'] );

		$normalize = static function ( array $rows ): array {
			$out = array();
			foreach ( $rows as $row ) {
				$attrs = array();
				foreach ( $row['attrs'] as $attr ) {
					$attrs[ $attr[0] ] = $attr[1];
				}
				ksort( $attrs );
				$out[] = array(
					'tag'          => $row['tag'],
					'fid'          => $row['fid'],
					'attrs'        => $attrs,
					'ancestorTags' => $row['ancestorTags'],
				);
			}
			return $out;
		};

		$expected = $normalize( $model_rows );
		$actual   = $normalize( $capture['htmlRows'] );
		if ( $expected !== $actual ) {
			$record(
				'model-desync',
				array(
					'processor' => 'fragment',
					'expected'  => $expected,
					'actual'    => $actual,
				)
			);
		}
	}

	/**
	 * Verifies that the processor's captured view of a safe (model-built)
	 * document agrees with the generated model — this guards the oracle
	 * itself against renderer/model drift, and is what justifies trusting
	 * the capture on wild documents.
	 */
	private static function check_capture_against_model( array $document, array $capture, callable $record ): void {
		$model_rows = DocumentGenerator::rows_from_model( $document['model'] );

		$normalize = static function ( array $rows, bool $with_ancestors ): array {
			$out = array();
			foreach ( $rows as $row ) {
				$attrs = array();
				foreach ( $row['attrs'] as $attr ) {
					$attrs[ $attr[0] ] = $attr[1];
				}
				ksort( $attrs );
				$normalized = array(
					'tag'   => $row['tag'],
					'fid'   => $row['fid'],
					'attrs' => $attrs,
				);
				if ( $with_ancestors ) {
					$normalized['ancestorTags'] = $row['ancestorTags'];
				}
				$out[] = $normalized;
			}
			return $out;
		};

		$expected = $normalize( $model_rows, true );
		$actual   = $normalize( $capture['htmlRows'], true );
		if ( $expected !== $actual ) {
			$record(
				'model-desync',
				array(
					'processor' => 'html',
					'expected'  => $expected,
					'actual'    => $actual,
				)
			);
		}

		$expected_tags = $normalize( $model_rows, false );
		$actual_tags   = $normalize( $capture['tagRows'], false );
		if ( $expected_tags !== $actual_tags ) {
			$record(
				'model-desync',
				array(
					'processor' => 'tag',
					'expected'  => $expected_tags,
					'actual'    => $actual_tags,
				)
			);
		}

		if ( $document['quirks'] !== $capture['quirks'] ) {
			$record(
				'model-desync',
				array(
					'processor' => 'quirks',
					'expected'  => $document['quirks'],
					'actual'    => $capture['quirks'],
				)
			);
		}
	}

	/**
	 * Runs a select() loop over the document, collecting matched data-fids.
	 *
	 * @param string $target   'html' or 'tag'.
	 * @param array  $document The case document ( may request fragment mode ).
	 * @return array{0: string[]|null, 1: \Throwable|null}
	 */
	private static function collect_matches( string $target, string $selector_string, array $document ): array {
		$html    = $document['html'];
		$context = ( $document['fragment'] ?? false ) ? $document['context'] : null;
		return self::guard(
			static function () use ( $target, $selector_string, $html, $context ) {
				if ( 'tag' === $target ) {
					$processor = new \WP_HTML_Tag_Processor( $html );
				} elseif ( null !== $context ) {
					$processor = \WP_HTML_Processor::create_fragment( $html, $context );
				} else {
					$processor = \WP_HTML_Processor::create_full_parser( $html );
				}

				$matches    = array();
				$iterations = 0;
				while ( $processor->select( $selector_string ) ) {
					$fid       = $processor->get_attribute( 'data-fid' );
					// Sanitize identically to TreeCapture/lexbor so a fid with
					// a control char can never produce a false divergence on
					// the match path ( unreachable today: fids are integers ).
					$matches[] = is_string( $fid ) ? TreeCapture::sanitize_fid( $fid ) : '(missing-fid:' . $processor->get_tag() . ')';
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
	}

	/**
	 * Flushes the select() parse caches.
	 *
	 * Both select() implementations memoize the most recently parsed selector
	 * string in a function-static cache, so whether a select() call re-parses
	 * — and therefore whether parse-time notices ( the invalid-UTF-8 scrub
	 * notice from from_selectors() ) fire — depends on what the worker
	 * happened to parse before. Parsing a sentinel selector first makes the
	 * next select() call for the case selector deterministic: it always
	 * re-parses, so exactly one parse happens inside each notice-assertion
	 * window regardless of worker history or case re-runs.
	 */
	private static function flush_select_parse_caches(): void {
		( new \WP_HTML_Tag_Processor( '' ) )->select( '#-fuzz-cache-flush-' );
		\WP_HTML_Processor::create_full_parser( '' )->select( '#-fuzz-cache-flush-' );
	}

	/**
	 * The _doing_it_wrong() name under which from_selectors() reports that an
	 * invalid-UTF-8 selector string was scrubbed to U+FFFD before parsing.
	 *
	 * @param string $target 'html' or 'tag'.
	 */
	private static function scrub_notice_name( string $target ): string {
		return ( 'tag' === $target ? 'WP_CSS_Compound_Selector_List' : 'WP_CSS_Complex_Selector_List' ) . '::from_selectors';
	}

	/**
	 * Runs a select() loop on a parseable selector and compares the match set
	 * against the reference matcher.
	 *
	 * @param string $target 'html' or 'tag'.
	 * @return string[]|null The actual match set, or null when matching failed.
	 */
	private static function check_select_matches( string $target, string $selector_string, array $document, array $expected, callable $record ): ?array {
		self::flush_select_parse_caches();
		Bootstrap::reset_doing_it_wrong();

		list( $actual, $error ) = self::collect_matches( $target, $selector_string, $document );

		if ( null !== $error ) {
			$record(
				'match-error',
				array(
					'target' => $target,
					'error'  => self::describe_throwable( $error ),
				)
			);
			return null;
		}

		/*
		 * A selector string containing invalid UTF-8 is scrubbed to U+FFFD by
		 * from_selectors(), which reports the replacement with exactly one
		 * notice on the (single, cache-flushed) parse. Anything else is
		 * unexpected for a selector that parses.
		 */
		$expected_calls = \wp_is_valid_utf8( $selector_string )
			? array()
			: array(
				array(
					'function' => self::scrub_notice_name( $target ),
				),
			);

		$doing_it_wrong = Bootstrap::doing_it_wrong_calls();
		if ( ! self::notices_match( $expected_calls, $doing_it_wrong ) ) {
			$record(
				'doing-it-wrong-unexpected',
				array(
					'target'        => $target,
					'expectedCalls' => $expected_calls,
					'calls'         => $doing_it_wrong,
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

		return $actual;
	}

	private static function note_match_assertion( array &$match_stats, string $target, array $expected, array $actual ): void {
		if ( ! isset( $match_stats[ $target ] ) ) {
			$match_stats[ $target ] = array(
				'assertions' => 0,
				'nonVacuous' => 0,
			);
		}

		++$match_stats[ $target ]['assertions'];
		if ( array() !== $expected || array() !== $actual ) {
			++$match_stats[ $target ]['nonVacuous'];
		}
	}

	private static function finalize_match_stats( array $match_stats ): array {
		foreach ( $match_stats as $bucket => $targets ) {
			foreach ( $targets as $target => $counts ) {
				$assertions  = (int) ( $counts['assertions'] ?? 0 );
				$non_vacuous = (int) ( $counts['nonVacuous'] ?? 0 );
				$vacuous     = max( 0, $assertions - $non_vacuous );

				$match_stats[ $bucket ][ $target ]['vacuous']         = $vacuous;
				$match_stats[ $bucket ][ $target ]['nonVacuousRate'] = $assertions > 0 ? round( $non_vacuous / $assertions, 4 ) : 0.0;
				$match_stats[ $bucket ][ $target ]['vacuousRate']    = $assertions > 0 ? round( $vacuous / $assertions, 4 ) : 0.0;
			}
		}
		return $match_stats;
	}

	/**
	 * Runs the lexbor differential — the THIRD, independent matching opinion.
	 *
	 * Quirks-mode documents are excluded unless the startup probe confirms
	 * lexbor has reliable class/#id case folding in both no-quirks and quirks
	 * mode. The comparison only runs when lexbor built the same element tree
	 * as WP ( fid/tag/ancestry multiset ), so it tests the selector layer,
	 * not tree construction.
	 *
	 * Verdict triage:
	 *  - 'lexbor-divergence'   lexbor != reference: a fuzzer-oracle problem
	 *                          ( or an un-compensated lexbor bug ) — never a
	 *                          WP verdict on its own.
	 *  - 'lexbor-parse-reject' lexbor refused a selector WP accepted.
	 *                          This is lexbor/fuzzer-oracle noise — never a
	 *                          WP verdict on its own.
	 *  - match-mismatch-html with NO lexbor-divergence and NO
	 *                          lexbor-parse-reject on the same case means
	 *                          reference == lexbor != WP: a high-confidence
	 *                          WP finding.
	 *
	 * @return string Tally state:
	 *   unavailable|skipped-quirks|error|tree-gated|compared.
	 */
	private static function check_lexbor_differential( array $complex_ast, string $selector_string, array $document, array $rows, bool $quirks, array $expected, callable $record ): string {
		if ( ! LexborOracle::available() ) {
			return 'unavailable';
		}
		if ( $quirks && ! LexborOracle::quirks_class_id_reliable() ) {
			return 'skipped-quirks';
		}

		/*
		 * Feed lexbor the exact selector bytes WP parsed. This intentionally
		 * keeps parser-level lexbor rejections visible as lexbor/fuzzer-oracle
		 * noise rather than canonicalizing them away.
		 */
		$lex = LexborOracle::query( $document['html'], $selector_string );
		if ( null === $lex ) {
			return 'error';
		}

		if ( 'parse' === $lex['error'] ) {
			$record(
				'lexbor-parse-reject',
				array(
					'classification' => 'lexbor/fuzzer-oracle',
					'wpFinding'      => false,
					'lexborError'    => $lex['error'],
					'note'           => 'lexbor rejected the same selector input that WP accepted',
					'selector'       => printable_bytes( $selector_string ),
				)
			);
			return 'compared';
		}
		if ( null !== $lex['error'] ) {
			return 'error';
		}

		if ( ! self::trees_agree( $rows, $lex['rows'] ) ) {
			return 'tree-gated';
		}

		/*
		 * Two known lexbor deviations are compensated for so the rest of the
		 * semantics still get differential coverage; WP itself is still held
		 * to the strict expectation:
		 *
		 *  - lexbor #368: class/#id match ASCII case-insensitively even in
		 *    no-quirks documents. Compare lexbor against the reference run
		 *    with quirks-style class/ID folding.
		 *  - lexbor does not implement HTML's case-insensitive attribute
		 *    value list ( [rel=NOFOLLOW] does not match rel="nofollow" ),
		 *    where browsers and WP do. Compare lexbor against the reference
		 *    run with that list disabled.
		 */
		$expected_for_lexbor = ReferenceMatcher::expected_html_matches_rows(
			$complex_ast,
			$rows,
			LexborOracle::has_issue_368() ? true : $quirks,
			false
		);

		// lexbor reports in document order, WP/reference in visit order —
		// compare as multisets.
		$lex_matches = $lex['matches'];
		sort( $lex_matches );
		sort( $expected_for_lexbor );

		if ( $lex_matches !== $expected_for_lexbor ) {
			$record(
				'lexbor-divergence',
				array(
					'classification' => 'lexbor/fuzzer-oracle',
					'wpFinding'      => false,
					'reference'      => $expected_for_lexbor,
					'lexbor'         => $lex_matches,
					'issue368'       => LexborOracle::has_issue_368(),
				)
			);
		}

		return 'compared';
	}

	/** Multiset equality of ( tag, fid, ancestry ) between WP and lexbor rows. */
	private static function trees_agree( array $wp_rows, array $lexbor_rows ): bool {
		$serialize = static function ( array $rows ): array {
			$out = array();
			foreach ( $rows as $row ) {
				$out[] = $row['tag'] . '|' . $row['fid'] . '|' . implode( ',', $row['ancestorTags'] );
			}
			sort( $out );
			return $out;
		};

		return $serialize( $wp_rows ) === $serialize( $lexbor_rows );
	}

	/**
	 * Checks the metamorphic relations: each meaning-preserving transform of
	 * the parsed selector must parse, must (for AST-preserving transforms)
	 * parse to exactly the transformed AST, and must select exactly the same
	 * elements the original selector selected.
	 *
	 * @param array    $complex_ast  Canonical AST of the original selector.
	 * @param string[] $html_matches The original's WP_HTML_Processor match set.
	 */
	private static function check_metamorphic( array $complex_ast, array $html_matches, array $document, Prng $prng, callable $record ): void {
		foreach ( Metamorph::variants( $complex_ast, $prng ) as $variant ) {
			$transform        = $variant['name'];
			$variant_selector = $variant['selector'];

			list( $variant_list, $parse_error ) = self::guard(
				static function () use ( $variant_selector ) {
					return \WP_CSS_Complex_Selector_List::from_selectors( $variant_selector );
				}
			);

			if ( null !== $parse_error ) {
				$record(
					'metamorphic-error',
					array(
						'transform' => $transform,
						'selector'  => printable_bytes( $variant_selector ),
						'error'     => self::describe_throwable( $parse_error ),
					)
				);
				continue;
			}

			if ( null === $variant_list ) {
				$record(
					'metamorphic-parse',
					array(
						'transform' => $transform,
						'selector'  => printable_bytes( $variant_selector ),
					)
				);
				continue;
			}

			if ( $variant['astMustMatch'] ) {
				list( $variant_ast, $shape_error ) = self::guard(
					static function () use ( $variant_list ) {
						return AstExtractor::from_complex_list( $variant_list );
					}
				);
				if ( null !== $shape_error ) {
					$record(
						'metamorphic-error',
						array(
							'transform' => $transform,
							'selector'  => printable_bytes( $variant_selector ),
							'error'     => self::describe_throwable( $shape_error ),
						)
					);
					continue;
				}
				if ( $variant_ast !== $variant['ast'] ) {
					$record(
						'metamorphic-ast',
						array(
							'transform'   => $transform,
							'selector'    => printable_bytes( $variant_selector ),
							'expectedAst' => $variant['ast'],
							'parsedAst'   => $variant_ast,
						)
					);
					continue;
				}
			}

			Bootstrap::reset_doing_it_wrong();
			list( $variant_matches, $match_error ) = self::collect_matches( 'html', $variant_selector, $document );

			if ( null !== $match_error ) {
				$record(
					'metamorphic-error',
					array(
						'transform' => $transform,
						'selector'  => printable_bytes( $variant_selector ),
						'error'     => self::describe_throwable( $match_error ),
					)
				);
				continue;
			}

			if ( $variant_matches !== $html_matches ) {
				$record(
					'metamorphic-mismatch',
					array(
						'transform' => $transform,
						'selector'  => printable_bytes( $variant_selector ),
						'expected'  => $html_matches,
						'actual'    => $variant_matches,
					)
				);
			}
		}
	}

	/**
	 * For unparseable selectors: select() must return false, leave the
	 * processor usable, and report misuse exactly once per call.
	 */
	private static function check_select_rejection( string $target, string $selector_string, array $document, callable $record ): void {
		self::flush_select_parse_caches();
		Bootstrap::reset_doing_it_wrong();

		$context = ( $document['fragment'] ?? false ) ? $document['context'] : null;
		list( $results, $error ) = self::guard(
			static function () use ( $target, $selector_string, $document, $context ) {
				if ( 'tag' === $target ) {
					$processor = new \WP_HTML_Tag_Processor( $document['html'] );
				} elseif ( null !== $context ) {
					$processor = \WP_HTML_Processor::create_fragment( $document['html'], $context );
				} else {
					$processor = \WP_HTML_Processor::create_full_parser( $document['html'] );
				}

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

		/*
		 * Two select() calls report the unparseable selector once each; the
		 * parse cache only skips re-parsing, never the per-call notice. An
		 * invalid-UTF-8 selector additionally reports the U+FFFD scrub once,
		 * on the first call ( the only one that parses after the flush ).
		 */
		$select_notice_name = ( 'tag' === $target ? 'WP_HTML_Tag_Processor' : 'WP_HTML_Processor' ) . '::select';
		$expected_calls     = array(
			array( 'function' => $select_notice_name ),
			array( 'function' => $select_notice_name ),
		);
		if ( ! \wp_is_valid_utf8( $selector_string ) ) {
			array_unshift( $expected_calls, array( 'function' => self::scrub_notice_name( $target ) ) );
		}

		$doing_it_wrong = Bootstrap::doing_it_wrong_calls();
		if ( ! self::notices_match( $expected_calls, $doing_it_wrong ) ) {
			$record(
				'doing-it-wrong-missing',
				array(
					'target'        => $target,
					'expectedCalls' => $expected_calls,
					'calls'         => $doing_it_wrong,
				)
			);
		}
	}

	/**
	 * Compares recorded _doing_it_wrong() calls against expectations: same
	 * count, in order, matching on every key the expectation specifies
	 * ( recorded calls also carry 'message', which expectations omit ).
	 *
	 * @param array[] $expected_calls Expected calls, each a subset of record keys.
	 * @param array[] $actual_calls   Recorded calls.
	 */
	private static function notices_match( array $expected_calls, array $actual_calls ): bool {
		if ( count( $expected_calls ) !== count( $actual_calls ) ) {
			return false;
		}
		foreach ( $expected_calls as $i => $expected_call ) {
			foreach ( $expected_call as $key => $value ) {
				if ( ( $actual_calls[ $i ][ $key ] ?? null ) !== $value ) {
					return false;
				}
			}
		}
		return true;
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
		$lexbor      = array();
		$match_stats = array();
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
			$lexbor[ $result['lexbor'] ]  = ( $lexbor[ $result['lexbor'] ] ?? 0 ) + 1;
			$last_seed                    = $seed;
			foreach ( $result['matchStats'] as $target => $stats ) {
				if ( ! isset( $match_stats[ $result['bucket'] ][ $target ] ) ) {
					$match_stats[ $result['bucket'] ][ $target ] = array(
						'assertions' => 0,
						'nonVacuous' => 0,
					);
				}
				$match_stats[ $result['bucket'] ][ $target ]['assertions'] += $stats['assertions'];
				$match_stats[ $result['bucket'] ][ $target ]['nonVacuous'] += $stats['nonVacuous'];
			}

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
			'lexbor'      => $lexbor,
			'matchStats'  => self::finalize_match_stats( $match_stats ),
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
		if ( isset( $failure['detail']['transform'] ) ) {
			$parts[] = $failure['detail']['transform'];
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
