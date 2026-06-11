<?php
namespace HtmlApiFuzz;

class Worker {
	public static function run( array $options ): array {
		$output_dir = option_string( $options, 'output-dir', getcwd() . DIRECTORY_SEPARATOR . 'html-api-fuzz-worker' );
		ensure_dir( $output_dir );

		$seed    = option_int( $options, 'seed', 1 );
		$profile = option_string( $options, 'profile', 'auto' );
		$mode    = option_string( $options, 'mode', 'auto' );
		$payload_policy_option = option_string( $options, 'payload-policy', null );
		$payload_policy        = $payload_policy_option ?? 'auto';
		$max_input_bytes_value = option_int( $options, 'max-input-bytes', 0 );
		$max_input_bytes       = $max_input_bytes_value > 0 ? $max_input_bytes_value : null;
		$fragment_context      = option_string( $options, 'fragment-context', 'body' );
		self::validate_fragment_context_metadata( $fragment_context );
		$generator_parameters  = null;
		$input_source          = 'generated';
		$git_metadata          = null === option_string( $options, 'git-metadata-base64', null )
			? git_metadata( 100 )
			: git_metadata_from_base64( option_string( $options, 'git-metadata-base64' ) );

		if ( null !== option_string( $options, 'input-base64', null ) ) {
			$input = base64_decode( option_string( $options, 'input-base64' ), true );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Invalid --input-base64.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
			$payload_policy = $payload_policy_option;
			$input_source   = 'input-base64';
			self::validate_profile_metadata( $profile );
			self::validate_mode_metadata( $mode );
			self::validate_payload_policy_metadata( $payload_policy );
		} elseif ( null !== option_string( $options, 'input-file', null ) ) {
			$input = file_get_contents( option_string( $options, 'input-file' ) );
			if ( false === $input ) {
				throw new \InvalidArgumentException( 'Could not read --input-file.' );
			}
			$profile = option_string( $options, 'profile', 'replay' );
			$mode    = option_string( $options, 'mode', Generator::MODE_FRAGMENT_BODY );
			$payload_policy = $payload_policy_option;
			$input_source   = 'input-file';
			self::validate_profile_metadata( $profile );
			self::validate_mode_metadata( $mode );
			self::validate_payload_policy_metadata( $payload_policy );
		} elseif ( self::seed_selects_corpus_stage( $seed, option_int( $options, 'corpus-mutate-percent', 20 ) ) ) {
			$corpus = self::corpus_mutated_input( $seed, $mode, $max_input_bytes );
			if ( null === $corpus ) {
				// Corpus unavailable: fall back to the generator.
				$generated            = Generator::generate( $seed, $profile ?? 'auto', $mode ?? 'auto', $payload_policy ?? 'auto', $max_input_bytes );
				$input                = $generated['input'];
				$profile              = $generated['profile'];
				$mode                 = $generated['mode'];
				$payload_policy       = $generated['payloadPolicy'];
				$fragment_context     = $generated['fragmentContext'];
				$generator_parameters = $generated['parameters'];
			} else {
				$input                = $corpus['input'];
				$profile              = 'corpus-mutated';
				$mode                 = $corpus['mode'];
				$payload_policy       = null;
				$fragment_context     = 'body';
				$generator_parameters = $corpus['parameters'];
				$input_source         = 'corpus-mutated';
			}
		} else {
			$generated            = Generator::generate( $seed, $profile ?? 'auto', $mode ?? 'auto', $payload_policy ?? 'auto', $max_input_bytes );
			$input                = $generated['input'];
			$profile              = $generated['profile'];
			$mode                 = $generated['mode'];
			$payload_policy       = $generated['payloadPolicy'];
			$fragment_context     = $generated['fragmentContext'];
			$generator_parameters = $generated['parameters'];
		}

		$limits = array(
			'maxTokens' => option_int( $options, 'max-tokens', 2000 ),
			'maxNodes'  => option_int( $options, 'max-nodes', 3000 ),
		);
		$fail_unsupported = option_bool( $options, 'fail-unsupported', false );
		$oracle_renderer  = OracleRenderer::from_options( $options );
		$oracle_metadata  = $oracle_renderer->metadata();

		$replay_path = $output_dir . DIRECTORY_SEPARATOR . 'replay.json';
		$result_path = $output_dir . DIRECTORY_SEPARATOR . 'result.json';
		$input_path  = $output_dir . DIRECTORY_SEPARATOR . 'input.bin';
		file_put_contents( $input_path, $input );

		$replay = self::base_replay( $seed, $profile, $mode, $payload_policy, $fragment_context, $generator_parameters, $input_source, $input, $output_dir, $limits, $fail_unsupported, $git_metadata, $oracle_metadata, $oracle_renderer->replay_options() );
		write_json_file( $replay_path, $replay );

		$tag_result = TagInvariants::check( $input, $limits, $mode, $fragment_context );
		$wp_result  = TreeRenderer::render_wordpress( $input, $mode, $limits, $fragment_context );
		if ( ! $oracle_renderer->is_php_dom() ) {
			$wp_result['domOracleLineTolerances'] = array();
		}
		$dom_result = array( 'status' => TreeRenderer::STATUS_ERROR, 'error' => 'Not run.', 'oracle' => $oracle_metadata );

		$result = array(
			'schemaVersion' => 1,
			'kind'          => 'html-api-fuzz-worker-result',
			'createdAt'     => gmdate( 'c' ),
			'ok'            => true,
			'status'        => 'passed',
			'seed'          => $seed,
			'profile'       => $profile,
			'mode'          => $mode,
			'payloadPolicy' => $payload_policy,
			'fragmentContext' => $fragment_context,
			'generator'     => $generator_parameters,
			'inputSource'   => $input_source,
			'inputSha1'     => sha1( $input ),
			'inputLength'   => strlen( $input ),
			'inputPreview'  => preview_bytes( $input ),
			'oracle'        => $oracle_metadata,
			'paths'         => array(
				'outputDir'  => $output_dir,
				'inputPath'  => $input_path,
				'replayPath' => $replay_path,
				'resultPath' => $result_path,
			),
			'tagProcessor'  => $tag_result,
			'wordpress'     => self::compact_parse_result( $wp_result, $output_dir, 'wordpress-tree.txt' ),
			'dom'           => $dom_result,
			'comparison'    => null,
		);

		if ( ! $tag_result['ok'] ) {
			$result['ok']           = false;
			$result['failureClass'] = self::tag_invariant_failure_class( $tag_result );
			$result['status']       = 'resource-limit' === $result['failureClass'] ? 'resource-limit' : 'failed';
		} elseif ( TreeRenderer::STATUS_UNSUPPORTED === $wp_result['status'] ) {
			$result['status']       = 'unsupported';
			$result['failureClass'] = 'unsupported';
			if ( $fail_unsupported ) {
				$result['ok'] = false;
			}
		} elseif ( TreeRenderer::STATUS_ERROR === $wp_result['status'] ) {
			$wp_failure_class       = $wp_result['failureClass'] ?? 'wordpress-parse-error';
			$result['ok']           = false;
			$result['status']       = self::is_resource_limit_failure( $wp_failure_class ) ? 'resource-limit' : 'failed';
			$result['failureClass'] = self::is_resource_limit_failure( $wp_failure_class ) ? 'resource-limit' : $wp_failure_class;
		} else {
			try {
				$dom_result = $oracle_renderer->render( $input, $mode, $limits, $fragment_context );
			} catch ( \Throwable $e ) {
				$dom_result = array(
					'status'       => TreeRenderer::STATUS_ERROR,
					'error'        => $e->getMessage(),
					'throwable'    => get_class( $e ),
					'failureClass' => 'oracle-renderer-error',
					'oracle'       => $oracle_metadata,
				);
			}
			$result['dom'] = self::compact_parse_result( $dom_result, $output_dir, 'dom-tree.txt' );

			if ( TreeRenderer::STATUS_ERROR === $dom_result['status'] ) {
				$dom_failure_class      = $dom_result['failureClass'] ?? 'oracle-renderer-error';
				$result['failureClass'] = self::is_resource_limit_failure( $dom_failure_class ) ? 'resource-limit' : $dom_failure_class;
				$result['status']       = self::is_resource_limit_failure( $dom_failure_class )
					? 'resource-limit'
					: ( 'oracle-parse-error' === $result['failureClass'] ? 'oracle-parse-error' : 'failed' );
				if ( 'oracle-parse-error' !== $result['failureClass'] ) {
					$result['ok'] = false;
				}
			} elseif ( TreeRenderer::STATUS_UNSUPPORTED === $dom_result['status'] ) {
				$result['status']       = 'oracle-unsupported';
				$result['failureClass'] = $dom_result['failureClass'] ?? 'oracle-unsupported';
			} else {
				$dom_oracle_line_tolerances = $oracle_renderer->is_php_dom() ? ( $wp_result['domOracleLineTolerances'] ?? array() ) : array();
				$comparison = TreeRenderer::compare_trees( $wp_result['tree'], $dom_result['tree'], $dom_oracle_line_tolerances );
				if ( $oracle_renderer->is_php_dom() && ! $comparison['ok'] && self::is_oracle_form_feed_quirk( $input, $mode, $limits, $fragment_context, $wp_result['tree'] ?? null, $dom_oracle_line_tolerances, $oracle_renderer ) ) {
					$comparison = array(
						'ok'                => true,
						'formFeedQuirk'     => true,
						'oracleFindingType' => 'dom-form-feed-pre-body-whitespace',
					);
					$result['status']       = 'oracle-tolerated';
					$result['failureClass'] = 'oracle-tolerated';
				} elseif ( $oracle_renderer->is_php_dom() && ! $comparison['ok'] && self::is_dom_mathml_heading_scope_quirk( $input, $mode, $comparison, $wp_result['tree'] ?? null, $dom_result['tree'] ?? null ) ) {
					$comparison = array(
						'ok'                => true,
						'oracleTolerated'   => true,
						'oracleFindingType' => 'dom-mathml-heading-scope-reparenting',
						'firstDifference'   => $comparison['firstDifference'] ?? array(),
					);
					$result['status']       = 'oracle-tolerated';
					$result['failureClass'] = 'oracle-tolerated';
				}
				$result['comparison'] = $comparison;
				if ( ! $comparison['ok'] ) {
					$result['ok']           = false;
					$result['status']       = 'failed';
					$result['failureClass'] = self::is_encoding_mismatch( $input, $comparison['firstDifference'] ?? array() )
						? 'encoding-mismatch'
						: 'tree-mismatch';
				} elseif ( ! empty( $dom_oracle_line_tolerances ) || ! empty( $comparison['scalarToleratedLines'] ) ) {
					$result['status']       = 'oracle-tolerated';
					$result['failureClass'] = 'oracle-tolerated';
				}
			}
		}

		$normalize_result = $tag_result['normalize'] ?? array( 'ok' => true );
		$normalized_html  = $normalize_result['normalizedHtml'] ?? null;
		unset( $result['tagProcessor']['normalize']['normalizedHtml'] );

		$has_clean_baseline = true === ( $result['ok'] ?? false )
			&& in_array( $result['status'] ?? null, array( 'passed', 'oracle-tolerated' ), true )
			&& true === ( $result['comparison']['ok'] ?? false );

		if ( $has_clean_baseline ) {
			$mutation           = self::check_mutation_differential( $input, $mode, $limits, $wp_result['tree'] ?? null, $oracle_renderer, $fragment_context );
			$result['mutation'] = $mutation;
			if ( false === $mutation['ok'] ) {
				$result['ok']           = false;
				$result['status']       = 'failed';
				$result['failureClass'] = $mutation['failureClass'];
				$has_clean_baseline     = false;
			}
		}

		if ( $has_clean_baseline && is_string( $normalized_html ) ) {
			$preservation                  = self::check_normalize_tree_preservation( $normalized_html, $mode, $limits, $wp_result['tree'] ?? null, $fragment_context );
			$result['normalizePreservation'] = $preservation;
			if ( false === $preservation['ok'] ) {
				$result['ok']           = false;
				$result['status']       = 'failed';
				$result['failureClass'] = 'normalize-tree-changed';
				$has_clean_baseline     = false;
			}
		}

		if (
			false === ( $normalize_result['ok'] ?? true ) &&
			true === ( $result['ok'] ?? false ) &&
			in_array( $result['status'] ?? null, array( 'passed', 'oracle-tolerated' ), true )
		) {
			$result['ok']           = false;
			$result['status']       = 'failed';
			$result['failureClass'] = 'normalize-invariant-failed';
		}

		$oracle_finding = OracleFinding::from_result( $result );
		if ( null !== $oracle_finding ) {
			$result['oracleFinding'] = $oracle_finding;
		}

		$signature = Signature::from_result( $result );
		if ( null !== $signature ) {
			$result['signature'] = $signature;
		}

		$replay['result']    = array(
			'ok'           => $result['ok'],
			'status'       => $result['status'],
			'failureClass' => $result['failureClass'] ?? null,
			'signature'    => $signature,
			'oracleFinding' => $result['oracleFinding'] ?? null,
			'oracle'       => $result['oracle'] ?? $oracle_metadata,
			'resultPath'   => $result_path,
		);
		$replay['signature'] = $signature;
		$replay['oracleFinding'] = $result['oracleFinding'] ?? null;
		write_json_file( $replay_path, $replay );
		write_json_file( $result_path, $result );

		return $result;
	}

	private static function validate_payload_policy_metadata( ?string $payload_policy ): void {
		if ( null !== $payload_policy && ! in_array( $payload_policy, Generator::payload_policy_labels(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator payload policy: ' . $payload_policy );
		}
	}

	private static function validate_profile_metadata( string $profile ): void {
		if ( ! in_array( $profile, array( 'replay', 'corpus-mutated' ), true ) && ! in_array( $profile, Generator::profiles(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator profile: ' . $profile );
		}
	}

	/**
	 * Deterministically routes a share of seeds to the corpus-mutation stage.
	 * Uses a hash of the seed rather than a modulus so stride-partitioned
	 * lanes (which see arithmetic seed progressions) still sample evenly.
	 */
	private static function seed_selects_corpus_stage( int $seed, int $percent ): bool {
		if ( $percent <= 0 ) {
			return false;
		}
		$bucket = hexdec( substr( hash( 'sha256', 'corpus-stage:' . $seed ), 0, 8 ) ) % 100;
		return $bucket < min( 100, $percent );
	}

	/**
	 * Builds a deterministic mutated input from the html5lib corpus.
	 */
	private static function corpus_mutated_input( int $seed, ?string $mode, ?int $max_input_bytes ): ?array {
		$entries = Corpus::entries();
		if ( array() === $entries ) {
			return null;
		}

		$rng   = new Prng( 'corpus:' . $seed );
		$index = $rng->int( 0, count( $entries ) - 1 );
		$entry = $entries[ $index ];

		$mutation = Mutator::mutate( $entry['data'], $rng, $entries );
		$input    = $mutation['input'];
		$truncated = false;
		if ( null !== $max_input_bytes && $max_input_bytes > 0 && strlen( $input ) > $max_input_bytes ) {
			$input     = substr( $input, 0, $max_input_bytes );
			$truncated = true;
		}

		$resolved_mode = ( null === $mode || 'auto' === $mode )
			? $rng->weighted( array( Generator::MODE_FRAGMENT_BODY => 60, Generator::MODE_FULL_DOCUMENT => 40 ) )
			: $mode;

		return array(
			'input'      => $input,
			'mode'       => $resolved_mode,
			'parameters' => array(
				'seed'        => $seed,
				'stage'       => 'corpus-mutated',
				'corpusFile'  => $entry['file'],
				'corpusIndex' => $index,
				'operations'  => $mutation['operations'],
				'truncated'   => $truncated,
				'byteLength'  => strlen( $input ),
			),
		);
	}

	private static function validate_fragment_context_metadata( string $fragment_context ): void {
		if ( ! in_array( $fragment_context, Generator::fragment_contexts(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown fragment context: ' . $fragment_context );
		}
	}

	private static function validate_mode_metadata( string $mode ): void {
		if ( ! in_array( $mode, Generator::modes(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown generator mode: ' . $mode );
		}
	}

	private static function base_replay( int $seed, string $profile, string $mode, ?string $payload_policy, string $fragment_context, ?array $generator_parameters, string $input_source, string $input, string $output_dir, array $limits, bool $fail_unsupported, array $git_metadata, array $oracle_metadata, array $oracle_options ): array {
		return array(
			'schemaVersion' => 1,
			'kind'          => 'html-api-fuzz-replay',
			'createdAt'     => gmdate( 'c' ),
			'repoRoot'      => repo_root(),
			'repoCommit'    => $git_metadata['commit'] ?? '',
			'repoDirty'     => $git_metadata['dirty'] ?? null,
			'phpVersion'    => PHP_VERSION,
			'seed'          => $seed,
			'profile'       => $profile,
			'mode'          => $mode,
			'payloadPolicy' => $payload_policy,
			'fragmentContext' => $fragment_context,
			'generator'     => $generator_parameters,
			'inputSource'   => $input_source,
			'inputBase64'   => base64_encode( $input ),
			'inputSha1'     => sha1( $input ),
			'inputLength'   => strlen( $input ),
			'inputPreview'  => preview_bytes( $input ),
			'limits'        => $limits,
			'oracle'        => $oracle_metadata,
			'options'       => array(
				'failUnsupported' => $fail_unsupported,
				'domOracle'       => $oracle_options['domOracle'] ?? OracleRenderer::KIND_PHP_DOM,
				'lexborOracleBin' => $oracle_options['lexborOracleBin'] ?? null,
				'oracleTimeoutMs' => $oracle_options['oracleTimeoutMs'] ?? null,
			),
			'command'       => array(
				'program' => PHP_BINARY,
				'args'    => array(
					'tools/html-api-fuzz/replay.php',
					'--replay',
					$output_dir . DIRECTORY_SEPARATOR . 'replay.json',
				),
				'cwd'     => repo_root(),
			),
		);
	}

	/**
	 * Differential check of a simple mutation: set data-fuzz="1" on the first
	 * tag, then verify the mutated document still parses identically in
	 * WordPress and the selected oracle, and that the WordPress tree changed by
	 * exactly that one attribute line.
	 *
	 * Runs only on a clean baseline so a failure is attributable to the
	 * mutation machinery rather than to a pre-existing divergence.
	 */
	private static function check_mutation_differential( string $input, string $mode, array $limits, ?string $original_tree, OracleRenderer $oracle_renderer, string $fragment_context = 'body' ): array {
		if ( ! is_string( $original_tree ) ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-no-baseline-tree',
			);
		}
		if ( false !== strpos( $input, 'data-fuzz' ) ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-input-contains-marker',
			);
		}

		$processor = new \WP_HTML_Tag_Processor( $input );
		if ( ! $processor->next_tag() || ! $processor->set_attribute( 'data-fuzz', '1' ) ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-no-mutable-tag',
			);
		}
		$updated = $processor->get_updated_html();

		$wp_updated = TreeRenderer::render_wordpress( $updated, $mode, $limits, $fragment_context );
		if ( TreeRenderer::STATUS_OK !== $wp_updated['status'] ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-wordpress-' . $wp_updated['status'],
			);
		}
		$dom_updated = $oracle_renderer->render( $updated, $mode, $limits, $fragment_context );
		if ( TreeRenderer::STATUS_OK !== $dom_updated['status'] ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-oracle-' . $dom_updated['status'],
			);
		}

		$dom_oracle_line_tolerances = $oracle_renderer->is_php_dom() ? ( $wp_updated['domOracleLineTolerances'] ?? array() ) : array();
		$comparison = TreeRenderer::compare_trees( $wp_updated['tree'], $dom_updated['tree'], $dom_oracle_line_tolerances );
		if ( ! $comparison['ok'] ) {
			if ( $oracle_renderer->is_php_dom() && self::is_oracle_form_feed_quirk( $updated, $mode, $limits, $fragment_context, $wp_updated['tree'], $dom_oracle_line_tolerances, $oracle_renderer ) ) {
				return array(
					'ok'     => true,
					'status' => 'skipped-oracle-form-feed-quirk',
				);
			}
			if ( $oracle_renderer->is_php_dom() && self::is_dom_mathml_heading_scope_quirk( $updated, $mode, $comparison, $wp_updated['tree'] ?? null, $dom_updated['tree'] ?? null ) ) {
				return array(
					'ok'     => true,
					'status' => 'skipped-oracle-mathml-heading-scope-quirk',
				);
			}
			return array(
				'ok'              => false,
				'status'          => 'failed',
				'failureClass'    => 'mutation-tree-mismatch',
				'message'         => 'The mutated document parses differently in WordPress and the selected oracle.',
				'firstDifference' => $comparison['firstDifference'] ?? array(),
			);
		}

		$marker        = 'data-fuzz="1"';
		$updated_lines = explode( "\n", $wp_updated['tree'] );
		$marker_lines  = array();
		foreach ( $updated_lines as $i => $line ) {
			if ( ltrim( $line, ' ' ) === $marker ) {
				$marker_lines[] = $i;
			}
		}

		if ( 0 === count( $marker_lines ) ) {
			/*
			 * Tree construction can legitimately drop the mutated element
			 * (for example <col> outside a table), taking the attribute with
			 * it. The mutation must then be a tree no-op.
			 */
			if ( $wp_updated['tree'] !== $original_tree ) {
				return array(
					'ok'              => false,
					'status'          => 'failed',
					'failureClass'    => 'mutation-delta-mismatch',
					'message'         => 'The attribute is missing from the re-parsed tree, yet the tree changed.',
					'firstDifference' => TreeRenderer::diff_trees( $wp_updated['tree'], $original_tree ),
				);
			}
			return array(
				'ok'     => true,
				'status' => 'passed-element-dropped',
			);
		}

		if ( count( $marker_lines ) > 1 ) {
			/*
			 * Active formatting element reconstruction clones attributes onto
			 * reconstructed elements, so a strict one-line delta does not
			 * apply. Correctness is still covered by the differential
			 * comparison of the mutated document above.
			 */
			return array(
				'ok'     => true,
				'status' => 'passed-differential-only',
			);
		}

		unset( $updated_lines[ $marker_lines[0] ] );
		$delta_tree = implode( "\n", $updated_lines );
		if ( $delta_tree !== $original_tree ) {
			return array(
				'ok'              => false,
				'status'          => 'failed',
				'failureClass'    => 'mutation-delta-mismatch',
				'message'         => 'The mutation changed the parsed tree beyond the added attribute.',
				'firstDifference' => TreeRenderer::diff_trees( $delta_tree, $original_tree ),
			);
		}

		return array(
			'ok'     => true,
			'status' => 'passed',
		);
	}

	/**
	 * Verifies that normalize() output parses to the same tree as its input:
	 * normalization may rewrite syntax but must not change document
	 * structure. Stricter than idempotence, which a consistently wrong
	 * serializer can satisfy.
	 */
	private static function check_normalize_tree_preservation( string $normalized_html, string $mode, array $limits, ?string $original_tree, string $fragment_context = 'body' ): array {
		if ( ! is_string( $original_tree ) ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-no-baseline-tree',
			);
		}

		$rendered = TreeRenderer::render_wordpress( $normalized_html, $mode, $limits, $fragment_context );
		if ( TreeRenderer::STATUS_OK !== $rendered['status'] ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-render-' . $rendered['status'],
			);
		}

		/*
		 * normalize() applies the spec scalar substitutions (NUL to U+FFFD,
		 * CR to LF) when serializing raw bytes the parser preserves, so the
		 * re-parsed tree may differ from the original by exactly those
		 * substitutions. Apply the same scrub-explained tolerance as the
		 * WordPress/DOM comparison; anything beyond it is a real change.
		 */
		$comparison = TreeRenderer::compare_trees( $original_tree, $rendered['tree'] );
		if ( ! $comparison['ok'] ) {
			return array(
				'ok'              => false,
				'status'          => 'failed',
				'failureClass'    => 'normalize-tree-changed',
				'message'         => 'Parsing normalize() output produced a different tree than the original input.',
				'firstDifference' => $comparison['firstDifference'] ?? array(),
			);
		}

		return array(
			'ok'                   => true,
			'status'               => empty( $comparison['scalarToleratedLines'] ) ? 'passed' : 'passed-scalar-tolerated',
			'scalarToleratedLines' => $comparison['scalarToleratedLines'] ?? array(),
		);
	}

	/**
	 * Detects mismatches fully explained by the DOM oracle's form feed bug
	 * (see TreeRenderer::dom_oracle_mishandles_form_feed()): when the oracle,
	 * given the input with form feeds substituted by spaces, produces exactly
	 * the WordPress tree, the divergence is the oracle's, not WordPress's.
	 * The substitution is conservative: form feeds in attribute values or
	 * body text make the trees differ and the tolerance simply not apply.
	 */
	private static function is_oracle_form_feed_quirk( string $input, string $mode, array $limits, string $fragment_context, ?string $wp_tree, array $dom_oracle_line_tolerances, OracleRenderer $oracle_renderer ): bool {
		if (
			! $oracle_renderer->is_php_dom() ||
			! is_string( $wp_tree ) ||
			Generator::MODE_FULL_DOCUMENT !== $mode ||
			false === strpos( $input, "" ) ||
			! TreeRenderer::dom_oracle_mishandles_form_feed()
		) {
			return false;
		}

		$substituted = $oracle_renderer->render( str_replace( "", ' ', $input ), $mode, $limits, $fragment_context );
		if ( TreeRenderer::STATUS_OK !== $substituted['status'] ) {
			return false;
		}

		$comparison = TreeRenderer::compare_trees( $wp_tree, $substituted['tree'], $dom_oracle_line_tolerances );
		return true === $comparison['ok'] && empty( $comparison['scalarToleratedLines'] );
	}

	private static function is_dom_mathml_heading_scope_quirk( string $input, string $mode, array $comparison, ?string $wp_tree = null, ?string $dom_tree = null ): bool {
		if ( ! in_array( $mode, array( Generator::MODE_FRAGMENT_BODY, Generator::MODE_FULL_DOCUMENT ), true ) ) {
			return false;
		}

		if (
			! preg_match( '/<math\b/i', $input ) ||
			! preg_match( '/<annotation-xml\b[^>]*\bencoding\s*=\s*(?:"|\')?\s*(?:text\/html|application\/xhtml\+xml)/i', $input ) ||
			! preg_match( '/<\/h[1-6]\s*>/i', $input )
		) {
			return false;
		}

		if ( ! is_string( $wp_tree ) || ! is_string( $dom_tree ) || ! self::tree_lines_match_ignoring_indentation( $wp_tree, $dom_tree ) ) {
			return false;
		}

		$diff = $comparison['firstDifference'] ?? array();
		if ( ! is_array( $diff ) || ( $diff['wordpressNorm'] ?? null ) !== ( $diff['domNorm'] ?? null ) ) {
			return false;
		}

		$wordpress_path = strtolower( (string) ( $diff['wordpressPath'] ?? '' ) );
		$dom_path       = strtolower( (string) ( $diff['domPath'] ?? '' ) );
		if ( '' === $wordpress_path || $wordpress_path === $dom_path ) {
			return false;
		}

		return false !== strpos( $wordpress_path, 'math annotation-xml' );
	}

	private static function tree_lines_match_ignoring_indentation( string $left, string $right ): bool {
		$left_lines  = explode( "\n", $left );
		$right_lines = explode( "\n", $right );
		if ( count( $left_lines ) !== count( $right_lines ) ) {
			return false;
		}

		foreach ( $left_lines as $i => $left_line ) {
			if ( ltrim( $left_line, ' ' ) !== ltrim( $right_lines[ $i ], ' ' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The wp_scrub_utf8() line comparison happens in
	 * TreeRenderer::first_difference(), where the full differing lines are
	 * available; the diff carries only truncated previews, which would
	 * misclassify long lines if scrubbed and compared here.
	 */
	private static function is_encoding_mismatch( string $input, array $diff ): bool {
		if ( function_exists( 'wp_is_valid_utf8' ) && wp_is_valid_utf8( $input ) ) {
			return false;
		}

		return true === ( $diff['linesMatchAfterWordPressUtf8Scrub'] ?? null );
	}

	private static function tag_invariant_failure_class( array $tag_result ): string {
		$failures = $tag_result['failures'] ?? array();
		if ( empty( $failures ) ) {
			return 'tag-invariant-failed';
		}

		$resource_limit_names = array( 'tag-token-limit-exceeded', 'mutation-token-limit-exceeded' );
		foreach ( $failures as $failure ) {
			if ( ! in_array( $failure['name'] ?? null, $resource_limit_names, true ) ) {
				return 'tag-invariant-failed';
			}
		}

		return 'resource-limit';
	}

	private static function is_resource_limit_failure( ?string $failure_class ): bool {
		return in_array( $failure_class, array( 'token-limit-exceeded', 'node-limit-exceeded' ), true );
	}

	private static function compact_parse_result( array $parse_result, string $output_dir, string $tree_filename ): array {
		if ( isset( $parse_result['tree'] ) ) {
			$tree_path = $output_dir . DIRECTORY_SEPARATOR . $tree_filename;
			file_put_contents( $tree_path, $parse_result['tree'] );
			$parse_result['treePath']    = $tree_path;
			$parse_result['treeSha1']    = sha1( $parse_result['tree'] );
			$parse_result['treePreview'] = preview_bytes( $parse_result['tree'], 400 );
			unset( $parse_result['tree'] );
		}
		return $parse_result;
	}
}
