<?php
namespace HtmlApiFuzz;

/**
 * Supervises isolated HTML API workers for documents supplied by cc-analyzer.
 *
 * The callback process never parses untrusted HTML itself. It persists the raw
 * response and a minimal replay record, starts one bounded child process, then
 * atomically publishes retained findings. A crash can therefore lose neither
 * the analyzer process nor the document that caused it.
 */
class CommonCrawlRunner {
	private const DEFAULT_MAX_INPUT_BYTES = 2097152;
	private const DEFAULT_MAX_TOKENS = 50000;
	private const DEFAULT_MAX_NODES = 50000;
	private const DEFAULT_MAX_DEPTH = 512;
	private const DEFAULT_MAX_TREE_BYTES = 16777216;
	private const DEFAULT_ORACLE_TIMEOUT_MS = 10000;
	private const DEFAULT_PROCESS_TIMEOUT_MS = 30000;
	private const DEFAULT_KEEP_PER_SIGNATURE = 3;

	private string $output_dir;
	private OracleRenderer $oracle;
	private array $limits;
	private int $max_input_bytes;
	private int $max_keep_per_signature;
	private int $process_timeout_ms;
	private bool $require_utf8;
	private bool $retain_all;
	private string $checks;
	private int $full_sample_percent;
	private string $memory_limit;
	private string $worker_script;
	private array $git_metadata;
	private string $run_id;
	private string $configuration_hash;
	private ?array $coordinator_provenance;

	private function __construct(
		string $output_dir,
		OracleRenderer $oracle,
		array $limits,
		int $max_input_bytes,
		int $max_keep_per_signature,
		int $process_timeout_ms,
		bool $require_utf8,
		bool $retain_all,
		string $checks,
		int $full_sample_percent,
		string $memory_limit,
		string $worker_script,
		string $run_id,
		?array $coordinator_provenance
	) {
		$this->output_dir             = $output_dir;
		$this->oracle                 = $oracle;
		$this->limits                 = $limits;
		$this->max_input_bytes        = $max_input_bytes;
		$this->max_keep_per_signature = $max_keep_per_signature;
		$this->process_timeout_ms     = $process_timeout_ms;
		$this->require_utf8           = $require_utf8;
		$this->retain_all             = $retain_all;
		$this->checks                 = $checks;
		$this->full_sample_percent    = $full_sample_percent;
		$this->memory_limit           = $memory_limit;
		$this->worker_script          = $worker_script;
		$this->git_metadata           = git_metadata( 1000, null, false );
		$this->run_id                 = $run_id;
		$this->coordinator_provenance = $coordinator_provenance;

		ensure_dir( $this->output_dir );
		$this->initialize_configuration();
	}

	public static function from_environment(): self {
		$output_dir = getenv( 'CC_ANALYZER_OUTPUT_DIR' );
		if ( ! is_string( $output_dir ) || '' === $output_dir ) {
			$run_id    = self::environment_string( 'HTML_API_CC_RUN_ID', 'run-' . timestamp() . '-' . getmypid() );
			$output_dir = repo_root() . '/artifacts/html-api-commoncrawl/' . $run_id;
		} else {
			$run_id = self::environment_string( 'HTML_API_CC_RUN_ID', 'cc-' . substr( hash( 'sha256', $output_dir ), 0, 12 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $run_id ) ) {
			throw new \InvalidArgumentException( 'HTML_API_CC_RUN_ID contains unsafe path characters.' );
		}

		$oracle_kind = self::environment_string( 'HTML_API_CC_ORACLE', OracleRenderer::KIND_LEXBOR_SOURCE );
		$oracle_options = array(
			'dom-oracle'        => $oracle_kind,
			'oracle-timeout-ms' => (string) self::environment_int( 'HTML_API_CC_ORACLE_TIMEOUT_MS', self::DEFAULT_ORACLE_TIMEOUT_MS, 1 ),
		);
		$oracle_bin = getenv( 'HTML_API_FUZZ_LEXBOR_ORACLE' );
		if ( is_string( $oracle_bin ) && '' !== $oracle_bin ) {
			$oracle_options['lexbor-oracle-bin'] = $oracle_bin;
		}
		$html5ever_oracle_bin = getenv( 'HTML_API_FUZZ_HTML5EVER_ORACLE' );
		if ( is_string( $html5ever_oracle_bin ) && '' !== $html5ever_oracle_bin ) {
			$oracle_options['html5ever-oracle-bin'] = $html5ever_oracle_bin;
		}
		$common_chrome_startup_timeout = getenv( 'HTML_API_CC_CHROME_STARTUP_TIMEOUT_MS' );
		if ( is_string( $common_chrome_startup_timeout ) && '' !== $common_chrome_startup_timeout ) {
			$oracle_options['chrome-startup-timeout-ms'] = (string) self::environment_int( 'HTML_API_CC_CHROME_STARTUP_TIMEOUT_MS', ChromeOracleRenderer::DEFAULT_STARTUP_TIMEOUT_MS, 1 );
		}

		$coordinator_provenance = self::coordinator_provenance_from_environment();
		$oracle = OracleRenderer::from_options( $oracle_options );
		return OracleRenderer::with_explicit_close(
			$oracle,
			static function ( OracleRenderer $oracle ) use ( $oracle_kind, $output_dir, $run_id, $coordinator_provenance ): self {
				$metadata = $oracle->metadata();
				if ( true !== ( $metadata['available'] ?? false ) ) {
					throw new \RuntimeException(
						"Selected {$oracle_kind} oracle is unavailable: " . (string) ( $metadata['error'] ?? 'unknown identity error' )
					);
				}
				$expected_commit = getenv( 'HTML_API_CC_EXPECT_LEXBOR_COMMIT' );
				if (
					is_string( $expected_commit ) && '' !== $expected_commit &&
					(
						OracleRenderer::KIND_LEXBOR_SOURCE !== $oracle_kind ||
						$expected_commit !== ( $metadata['identity']['lexborCommit'] ?? null )
					)
				) {
					throw new \RuntimeException( 'Lexbor oracle commit does not match HTML_API_CC_EXPECT_LEXBOR_COMMIT.' );
				}
				$expected_identity_sha256 = getenv( 'HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256' );
				if ( is_string( $expected_identity_sha256 ) && '' !== $expected_identity_sha256 && 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $expected_identity_sha256 ) ) {
					throw new \InvalidArgumentException( 'HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256 must be a SHA-256 hex digest.' );
				}
				if (
					is_string( $expected_identity_sha256 ) && '' !== $expected_identity_sha256 &&
					! hash_equals( strtolower( $expected_identity_sha256 ), OracleRenderer::identity_sha256( $metadata ) )
				) {
					throw new \RuntimeException( 'Oracle identity does not match HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256.' );
				}

				$checks = self::environment_string( 'HTML_API_CC_CHECKS', 'sampled' );
				if ( ! in_array( $checks, array( 'baseline', 'full', 'sampled' ), true ) ) {
					throw new \InvalidArgumentException( 'HTML_API_CC_CHECKS must be baseline, full, or sampled.' );
				}
				$full_sample_percent = self::environment_int( 'HTML_API_CC_FULL_SAMPLE_PERCENT', 1, 0 );
				if ( $full_sample_percent > 100 ) {
					throw new \InvalidArgumentException( 'HTML_API_CC_FULL_SAMPLE_PERCENT must be at most 100.' );
				}
				$memory_limit = self::environment_string( 'HTML_API_CC_MEMORY_LIMIT', '256M' );
				if ( ! preg_match( '/^[1-9][0-9]*[KMG]?$/i', $memory_limit ) ) {
					throw new \InvalidArgumentException( 'HTML_API_CC_MEMORY_LIMIT must be a positive PHP memory limit such as 256M.' );
				}
				$worker_script = self::environment_string( 'HTML_API_CC_WORKER_SCRIPT', repo_root() . '/tools/html-api-fuzz/worker.php' );
				if ( ! is_file( $worker_script ) ) {
					throw new \RuntimeException( 'HTML_API_CC_WORKER_SCRIPT does not exist.' );
				}
				if ( ! function_exists( 'posix_kill' ) || ! function_exists( 'posix_setsid' ) || ! function_exists( 'pcntl_exec' ) ) {
					throw new \RuntimeException( 'Common Crawl worker isolation requires the POSIX and PCNTL PHP extensions.' );
				}

				$process_timeout_environment = getenv( 'HTML_API_CC_PROCESS_TIMEOUT_MS' );
				$process_timeout_ms = is_string( $process_timeout_environment ) && '' !== $process_timeout_environment
					? self::environment_int( 'HTML_API_CC_PROCESS_TIMEOUT_MS', self::DEFAULT_PROCESS_TIMEOUT_MS, 1 )
					: $oracle->recommended_process_timeout_ms( $checks, self::DEFAULT_PROCESS_TIMEOUT_MS );

				return new self(
					$output_dir,
					$oracle,
					array(
						'maxTokens'    => self::environment_int( 'HTML_API_CC_MAX_TOKENS', self::DEFAULT_MAX_TOKENS, 1 ),
						'maxNodes'     => self::environment_int( 'HTML_API_CC_MAX_NODES', self::DEFAULT_MAX_NODES, 1 ),
						'maxDepth'     => self::environment_int( 'HTML_API_CC_MAX_DEPTH', self::DEFAULT_MAX_DEPTH, 1 ),
						'maxTreeBytes' => self::environment_int( 'HTML_API_CC_MAX_TREE_BYTES', self::DEFAULT_MAX_TREE_BYTES, 1 ),
					),
					self::environment_int( 'HTML_API_CC_MAX_INPUT_BYTES', self::DEFAULT_MAX_INPUT_BYTES, 0 ),
					self::environment_int( 'HTML_API_CC_MAX_KEEP_PER_SIGNATURE', self::DEFAULT_KEEP_PER_SIGNATURE, 1 ),
					$process_timeout_ms,
					self::environment_bool( 'HTML_API_CC_REQUIRE_UTF8', true ),
					self::environment_bool( 'HTML_API_CC_RETAIN_ALL', false ),
					$checks,
					$full_sample_percent,
					$memory_limit,
					$worker_script,
					$run_id,
					$coordinator_provenance
				);
			}
		);
	}

	/** Analyze one CcAnalyzer\Analysis\HtmlAnalysisInput-compatible object. */
	public function analyze_document( object $document ): array {
		$started_at = hrtime( true );
		$metadata   = $this->document_metadata( $document );
		$body       = $document->body ?? null;

		if ( ! is_string( $body ) ) {
			return $this->record_callback_error( $metadata, new \InvalidArgumentException( 'cc-analyzer document body must be a string.' ), $started_at );
		}

		$metadata['byteLength']  = strlen( $body );
		$metadata['inputSha1']   = sha1( $body );
		$metadata['inputSha256'] = hash( 'sha256', $body );
		$metadata['utf8Valid']  = 1 === preg_match( '//u', $body );
		if ( $this->max_input_bytes > 0 && strlen( $body ) > $this->max_input_bytes ) {
			return $this->record_skip( $metadata, 'skipped-input-too-large', $started_at );
		}
		if ( $this->require_utf8 && ! $metadata['utf8Valid'] ) {
			return $this->record_skip( $metadata, 'skipped-invalid-utf8', $started_at );
		}

		$seed        = self::seed_for_record( $metadata['recordId'], $metadata['inputSha1'] );
		$checks      = $this->checks_for_seed( $seed );
		$staging_dir = $this->create_staging_dir( $metadata );
		try {
			$this->persist_initial_input( $staging_dir, $body, $metadata, $seed, $checks );
			$process = run_php_process( $this->worker_args( $staging_dir, $seed, $checks ), repo_root(), $this->process_timeout_ms, $staging_dir . '/worker.log', 1048576, true );
			$result  = $this->load_or_synthesize_result( $staging_dir, $process, $body, $seed, $checks );
			$result  = $this->enforce_worker_input_identity( $result, $body );
			$result  = $this->enforce_worker_oracle_identity( $result );
			$result['profile']      = 'commoncrawl';
			$result['inputSource']  = 'commoncrawl';
			$result['inputSha256']  = $metadata['inputSha256'];
			$result['commonCrawl']  = $metadata;
			$result['run']          = $this->run_metadata();
			$result['repo']         = $this->git_metadata;
			$result['oracleResult'] = $result['dom'] ?? null; // New neutral name; dom remains for replay compatibility.
			$result['process']      = self::compact_process( $process );
			$result['durationMs']   = self::elapsed_ms( $started_at );
			if ( false === ( $result['ok'] ?? false ) && ! is_array( $result['signature'] ?? null ) ) {
				$signature = Signature::from_result( $result );
				if ( null !== $signature ) {
					$result['signature'] = $signature;
				}
			}

			$has_oracle_finding = is_array( $result['oracleFinding'] ?? null );
			$should_retain      = $this->retain_all || false === ( $result['ok'] ?? false ) || $has_oracle_finding;
			$artifact_dir       = $should_retain ? $this->publish_staging( $staging_dir, $result, $metadata ) : null;
			if ( ! $should_retain ) {
				remove_dir_recursive( $staging_dir );
			}

			$summary = $this->summary_from_result( $result, $metadata, $artifact_dir );
			$this->append_summary( $summary );
			return $summary;
		} catch ( \Throwable $throwable ) {
			// Deliberately leave staging in pending/ for manual recovery.
			return $this->record_callback_error( $metadata, $throwable, $started_at, $body, $staging_dir );
		}
	}

	private function enforce_worker_oracle_identity( array $result ): array {
		$expected = $this->oracle->metadata();
		$actual = $result['oracle'] ?? null;
		$mismatches = OracleRenderer::identity_mismatches( $expected, is_array( $actual ) ? $actual : array() );
		if ( empty( $mismatches ) ) {
			return $result;
		}
		$result['ok'] = false;
		$result['status'] = 'oracle-identity-drift';
		$result['failureClass'] = 'oracle-identity-drift';
		$result['failureSnippet'] = implode( '; ', $mismatches );
		$result['sourceOracle'] = $expected;
		$result['actualOracle'] = $actual;
		$result['oracleIdentityMismatches'] = $mismatches;
		unset( $result['signature'], $result['oracleFinding'], $result['comparison'] );
		return $result;
	}

	private function enforce_worker_input_identity( array $result, string $body ): array {
		$expected = array(
			'inputSha1'   => sha1( $body ),
			'inputLength' => strlen( $body ),
		);
		$actual = array(
			'inputSha1'   => $result['inputSha1'] ?? null,
			'inputLength' => $result['inputLength'] ?? null,
		);
		if ( $expected === $actual ) {
			return $result;
		}

		$result['ok']                   = false;
		$result['status']               = 'worker-input-identity-drift';
		$result['failureClass']         = 'worker-input-identity-drift';
		$result['failureSnippet']       = 'Worker result input identity did not match the parent-persisted Common Crawl body.';
		$result['workerInfrastructure'] = true;
		$result['expectedInputIdentity'] = $expected;
		$result['actualInputIdentity']   = $actual;
		unset( $result['signature'], $result['oracleFinding'], $result['comparison'] );
		return $result;
	}

	private function worker_args( string $staging_dir, int $seed, string $checks ): array {
		$args = array(
			'-d', 'memory_limit=' . $this->memory_limit,
			$this->worker_script,
			'--input-file', $staging_dir . '/input.bin',
			'--mode', Generator::MODE_FULL_DOCUMENT,
			'--profile', 'commoncrawl',
			'--seed', (string) $seed,
			'--output-dir', $staging_dir,
			'--max-tokens', (string) $this->limits['maxTokens'],
			'--max-nodes', (string) $this->limits['maxNodes'],
			'--max-depth', (string) $this->limits['maxDepth'],
			'--max-tree-bytes', (string) $this->limits['maxTreeBytes'],
			'--checks', $checks,
			'--process-timeout-ms', (string) $this->process_timeout_ms,
			'--git-metadata-base64', git_metadata_base64( $this->git_metadata ),
		);
		return array_merge( $args, $this->oracle->worker_args() );
	}

	private function create_staging_dir( array $metadata ): string {
		$document_key = hash( 'sha256', (string) $metadata['recordId'] . "\0" . (string) $metadata['inputSha1'] );
		$random       = bin2hex( random_bytes( 6 ) );
		$path         = $this->output_dir . '/pending/' . substr( $document_key, 0, 20 ) . '-' . getmypid() . '-' . $random;
		ensure_dir( $path );
		return $path;
	}

	private function persist_initial_input( string $staging_dir, string $body, array $metadata, int $seed, string $checks ): void {
		$input_path = $staging_dir . '/input.bin';
		write_file_atomic( $input_path, $body );
		$oracle_options = $this->oracle->replay_options();
		$replay_options = array_merge(
			array( 'failUnsupported' => false, 'checks' => $checks ),
			$oracle_options,
			array(
				'memoryLimit'      => $this->memory_limit,
				'processTimeoutMs' => $this->process_timeout_ms,
				'workerScript'     => realpath( $this->worker_script ) ?: $this->worker_script,
			)
		);
		write_json_file_atomic(
			$staging_dir . '/replay.json',
			array(
				'schemaVersion' => 1,
				'kind'          => 'html-api-fuzz-replay',
				'createdAt'     => gmdate( 'c' ),
				'run'           => $this->run_metadata(),
				'repoRoot'      => repo_root(),
				'repoCommit'    => $this->git_metadata['commit'] ?? null,
				'repoDirty'     => $this->git_metadata['dirty'] ?? null,
				'phpVersion'    => PHP_VERSION,
				'seed'          => $seed,
				'profile'       => 'commoncrawl',
				'mode'          => Generator::MODE_FULL_DOCUMENT,
				'payloadPolicy' => null,
				'fragmentContext' => 'body',
				'generator'     => null,
				'inputSource'   => 'commoncrawl',
				'inputBase64'   => base64_encode( $body ),
				'inputSha1'     => sha1( $body ),
				'inputSha256'   => hash( 'sha256', $body ),
				'inputLength'   => strlen( $body ),
				'inputPreview'  => preview_bytes( $body ),
				'limits'        => $this->limits,
				'oracle'        => $this->oracle->metadata(),
				'options'       => $replay_options,
				'commonCrawl'   => $metadata,
				'status'        => 'pending-worker',
			)
		);
	}

	private function load_or_synthesize_result( string $staging_dir, array $process, string $body, int $seed, string $checks ): array {
		$result = null;
		try {
			$result = read_json_file( $staging_dir . '/result.json' );
		} catch ( \Throwable $ignored ) {
			$result = null;
		}
		if ( is_array( $result ) && false === ( $process['processGroupCleanupFailed'] ?? false ) ) {
			return $result;
		}

		return synthesize_worker_process_failure(
			$process,
			array(
				'seed'        => $seed,
				'profile'     => 'commoncrawl',
				'mode'        => Generator::MODE_FULL_DOCUMENT,
				'inputSource' => 'commoncrawl',
				'inputSha1'   => sha1( $body ),
				'inputSha256' => hash( 'sha256', $body ),
				'inputLength' => strlen( $body ),
				'checks'      => $checks,
				'oracle'      => $this->oracle->metadata(),
			)
		);
	}

	private function publish_staging( string $staging_dir, array &$result, array $metadata ): ?string {
		$signature = $result['signature'] ?? $result['oracleFinding']['signature'] ?? null;
		$hash      = is_array( $signature ) && is_string( $signature['hash'] ?? null )
			? $signature['hash']
			: substr( hash( 'sha256', (string) ( $result['failureClass'] ?? $result['status'] ?? 'retained' ) ), 0, 16 );
		$record_key   = (string) $metadata['recordId'] . "\0" . (string) $metadata['inputSha1'];
		$document_key = substr( hash( 'sha256', $record_key ), 0, 20 );
		$signature_dir = $this->output_dir . '/findings/' . preg_replace( '/[^A-Za-z0-9._-]/', '_', $hash );
		$final_dir     = $signature_dir . '/' . $document_key;
		$lock          = $this->retention_lock();

		try {
			ensure_dir( $signature_dir );
			if ( is_file( $final_dir . '/.complete' ) ) {
				$result = self::replace_path_prefix( $result, $staging_dir, $final_dir );
				remove_dir_recursive( $staging_dir );
				return $final_dir;
			}

			$complete_count = 0;
			foreach ( scandir( $signature_dir ) ?: array() as $entry ) {
				if ( is_file( $signature_dir . '/' . $entry . '/.complete' ) ) {
					++$complete_count;
				}
			}
			if ( ! $this->retain_all && $complete_count >= $this->max_keep_per_signature ) {
				remove_dir_recursive( $staging_dir );
				return null;
			}

			$result = self::replace_path_prefix( $result, $staging_dir, $final_dir );
			$result['paths'] = array(
				'outputDir'  => $final_dir,
				'inputPath'  => $final_dir . '/input.bin',
				'replayPath' => $final_dir . '/replay.json',
				'resultPath' => $final_dir . '/result.json',
			);
			$replay = read_json_file( $staging_dir . '/replay.json' );
			if ( ! is_array( $replay ) ) {
				$replay = array( 'kind' => 'html-api-fuzz-replay' );
			}
			$replay = self::replace_path_prefix( $replay, $staging_dir, $final_dir );
			$replay['run']         = $this->run_metadata();
			$replay['repoRoot']    = repo_root();
			$replay['repoCommit']  = $this->git_metadata['commit'] ?? null;
			$replay['repoDirty']   = $this->git_metadata['dirty'] ?? null;
			$replay['commonCrawl'] = $metadata;
			$replay['inputSha1']    = $metadata['inputSha1'] ?? $replay['inputSha1'] ?? null;
			$replay['inputSha256']  = $metadata['inputSha256'] ?? null;
			$replay['inputLength']  = $metadata['byteLength'] ?? $replay['inputLength'] ?? null;
			$replay['oracle']      = $this->oracle->metadata();
			if ( 'oracle-identity-drift' === ( $result['failureClass'] ?? null ) ) {
				$replay['sourceOracle'] = $result['sourceOracle'] ?? $this->oracle->metadata();
				$replay['actualOracle'] = $result['actualOracle'] ?? null;
				$replay['oracleIdentityMismatches'] = $result['oracleIdentityMismatches'] ?? array();
			}
			$replay['options']['checks'] = $result['checks'] ?? $this->checks_for_seed( (int) ( $result['seed'] ?? 1 ) );
			$replay['options']['memoryLimit'] = $this->memory_limit;
			$replay['options']['processTimeoutMs'] = $this->process_timeout_ms;
			$replay['options']['workerScript'] = realpath( $this->worker_script ) ?: $this->worker_script;
			$replay['signature']     = $result['signature'] ?? null;
			$replay['oracleFinding'] = $result['oracleFinding'] ?? null;
			$replay['command'] = array(
				'program' => PHP_BINARY,
				'args'    => array( 'tools/html-api-fuzz/replay.php', '--replay', $final_dir . '/replay.json' ),
				'cwd'     => repo_root(),
			);
			$replay['result'] = array(
				'ok'            => $result['ok'] ?? false,
				'status'        => $result['status'] ?? 'unknown',
				'failureClass'  => $result['failureClass'] ?? null,
				'signature'     => $result['signature'] ?? null,
				'oracleFinding' => $result['oracleFinding'] ?? null,
				'oracle'        => $result['oracle'] ?? $this->oracle->metadata(),
				'resultPath'    => $final_dir . '/result.json',
			);
			write_json_file_atomic( $staging_dir . '/result.json', $result );
			write_json_file_atomic( $staging_dir . '/replay.json', $replay );
			$marker = "complete\n";
			if ( strlen( $marker ) !== file_put_contents( $staging_dir . '/.complete', $marker ) ) {
				throw new \RuntimeException( 'Could not write finding completion marker.' );
			}
			if ( is_dir( $final_dir ) ) {
				remove_dir_recursive( $final_dir );
			}
			if ( ! rename( $staging_dir, $final_dir ) ) {
				throw new \RuntimeException( 'Could not atomically publish Common Crawl finding.' );
			}
			return $final_dir;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function retention_lock() {
		$path = $this->output_dir . '/.retention.lock';
		$file = fopen( $path, 'c+b' );
		if ( false === $file || ! flock( $file, LOCK_EX ) ) {
			if ( is_resource( $file ) ) {
				fclose( $file );
			}
			throw new \RuntimeException( 'Could not lock Common Crawl artifact retention state.' );
		}
		return $file;
	}

	private static function replace_path_prefix( $value, string $from, string $to ) {
		if ( is_string( $value ) ) {
			return 0 === strpos( $value, $from ) ? $to . substr( $value, strlen( $from ) ) : $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_path_prefix( $item, $from, $to );
			}
		}
		return $value;
	}

	private function record_skip( array $metadata, string $status, int $started_at ): array {
		$summary = array_merge( $this->base_summary(), array(
			'ok'                  => true,
			'status'              => $status,
			'differentialCovered' => false,
			'durationMs'          => self::elapsed_ms( $started_at ),
			'commonCrawl'         => $metadata,
			'oracle'              => $this->oracle->metadata(),
		) );
		$this->append_summary( $summary );
		return $summary;
	}

	private function record_callback_error( array $metadata, \Throwable $throwable, int $started_at, ?string $body = null, ?string $staging_dir = null ): array {
		$result = array(
			'schemaVersion'  => 1,
			'kind'           => 'html-api-fuzz-worker-result',
			'createdAt'      => gmdate( 'c' ),
			'ok'             => false,
			'status'         => 'commoncrawl-callback-error',
			'failureClass'   => 'commoncrawl-callback-error',
			'failureSnippet' => $throwable->getMessage(),
			'throwable'      => get_class( $throwable ),
			'profile'        => 'commoncrawl',
			'mode'           => Generator::MODE_FULL_DOCUMENT,
			'inputSource'    => 'commoncrawl',
			'inputSha1'      => $metadata['inputSha1'] ?? ( null === $body ? null : sha1( $body ) ),
			'inputSha256'    => $metadata['inputSha256'] ?? ( null === $body ? null : hash( 'sha256', $body ) ),
			'inputLength'    => $metadata['byteLength'] ?? ( null === $body ? null : strlen( $body ) ),
			'oracle'         => $this->oracle->metadata(),
			'commonCrawl'    => $metadata,
			'run'            => $this->run_metadata(),
			'repo'           => $this->git_metadata,
			'durationMs'     => self::elapsed_ms( $started_at ),
		);
		$result['signature'] = Signature::from_result( $result );
		$summary = $this->summary_from_result( $result, $metadata, $staging_dir );
		$summary['pendingRecovery'] = null !== $staging_dir;
		$this->append_summary( $summary );
		return $summary;
	}

	private function summary_from_result( array $result, array $metadata, ?string $artifact_dir ): array {
		return array_merge( $this->base_summary(), array(
			'kind'                => false === ( $result['ok'] ?? false ) ? 'commoncrawl-failure' : 'commoncrawl-attempt',
			'createdAt'           => $result['createdAt'] ?? gmdate( 'c' ),
			'ok'                  => (bool) ( $result['ok'] ?? false ),
			'status'              => $result['status'] ?? 'unknown',
			'failureClass'        => $result['failureClass'] ?? null,
			'differentialCovered' => is_array( $result['comparison'] ?? null ),
			'seed'                => $result['seed'] ?? null,
			'checks'              => $result['checks'] ?? $this->checks,
			'inputSha1'           => $metadata['inputSha1'] ?? null,
			'inputSha256'         => $metadata['inputSha256'] ?? null,
			'inputLength'         => $metadata['byteLength'] ?? null,
			'signature'           => $result['signature'] ?? null,
			'oracleFinding'       => $result['oracleFinding'] ?? null,
			'oracle'              => $result['oracle'] ?? $this->oracle->metadata(),
			'sourceOracle'        => $result['sourceOracle'] ?? null,
			'actualOracle'        => $result['actualOracle'] ?? null,
			'oracleIdentityMismatches' => $result['oracleIdentityMismatches'] ?? array(),
			'artifactsRetained'   => null !== $artifact_dir,
			'artifactDir'         => $artifact_dir,
			'durationMs'          => $result['durationMs'] ?? null,
			'timingsMs'           => $result['timingsMs'] ?? null,
			'process'             => $result['process'] ?? null,
			'commonCrawl'         => $metadata,
		) );
	}

	private function base_summary(): array {
		return array(
			'kind'        => 'commoncrawl-attempt',
			'createdAt'   => gmdate( 'c' ),
			'runId'       => $this->run_id,
			'configHash'  => $this->configuration_hash,
			'repo'        => $this->git_metadata,
			'profile'     => 'commoncrawl',
			'mode'        => Generator::MODE_FULL_DOCUMENT,
			'inputSource' => 'commoncrawl',
			'coordinator' => $this->coordinator_provenance,
		);
	}

	private function append_summary( array $summary ): void {
		append_ndjson( $this->output_dir . '/commoncrawl-summary.ndjson', $summary );
		try {
			$this->update_coverage( $summary );
		} catch ( \Throwable $ignored ) {
			// coverage.json is a rebuildable snapshot; summary.ndjson is the
			// durable source of truth and must not be reclassified or duplicated.
		}
	}

	private function update_coverage( array $summary ): void {
		$path = $this->output_dir . '/coverage.json';
		$lock = fopen( $this->output_dir . '/.coverage.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new \RuntimeException( 'Could not lock Common Crawl coverage counters.' );
		}
		try {
			try {
				$coverage = read_json_file( $path );
			} catch ( \Throwable $ignored ) {
				$coverage = null;
			}
			$summary_size = @filesize( $this->output_dir . '/commoncrawl-summary.ndjson' );
			if (
				! is_array( $coverage ) ||
				! is_int( $coverage['summaryBytes'] ?? null ) ||
				false === $summary_size ||
				$coverage['summaryBytes'] > $summary_size
			) {
				$coverage = $this->empty_coverage();
			}
			$this->consume_summary_records( $coverage );
			$coverage['coverageRate'] = 0 === $coverage['total'] ? 0 : round( $coverage['covered'] / $coverage['total'], 6 );
			$coverage['updatedAt'] = gmdate( 'c' );
			write_json_file_atomic( $path, $coverage );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function empty_coverage(): array {
		return array(
			'kind'       => 'html-api-commoncrawl-coverage',
			'runId'      => $this->run_id,
			'configHash' => $this->configuration_hash,
			'total'      => 0,
			'covered'    => 0,
			'failures'   => 0,
			'statuses'   => array(),
			'summaryBytes' => 0,
		);
	}

	private function consume_summary_records( array &$coverage ): void {
		$path = $this->output_dir . '/commoncrawl-summary.ndjson';
		$file = fopen( $path, 'rb' );
		if ( false === $file || ! flock( $file, LOCK_SH ) ) {
			throw new \RuntimeException( 'Could not read locked Common Crawl summaries for coverage.' );
		}
		try {
			if ( 0 !== fseek( $file, (int) $coverage['summaryBytes'] ) ) {
				throw new \RuntimeException( 'Could not seek Common Crawl coverage cursor.' );
			}
			$cursor = (int) $coverage['summaryBytes'];
			while ( false !== ( $line = fgets( $file ) ) ) {
				if ( '' === $line || "\n" !== substr( $line, -1 ) ) {
					break;
				}
				$record = json_decode( $line, true );
				if ( is_array( $record ) ) {
					$this->add_coverage_record( $coverage, $record );
				}
				$position = ftell( $file );
				if ( false !== $position ) {
					$cursor = $position;
				}
			}
			$coverage['summaryBytes'] = $cursor;
		} finally {
			flock( $file, LOCK_UN );
			fclose( $file );
		}
	}

	private function add_coverage_record( array &$coverage, array $summary ): void {
		++$coverage['total'];
		if ( $summary['differentialCovered'] ?? false ) {
			++$coverage['covered'];
		}
		if ( false === ( $summary['ok'] ?? false ) ) {
			++$coverage['failures'];
		}
		$status = (string) ( $summary['status'] ?? 'unknown' );
		$coverage['statuses'][ $status ] = 1 + (int) ( $coverage['statuses'][ $status ] ?? 0 );
	}

	private function initialize_configuration(): void {
		$configuration = array(
			'kind'                => 'html-api-commoncrawl-configuration',
			'runId'               => $this->run_id,
			'repo'                => $this->git_metadata,
			'phpVersion'          => PHP_VERSION,
			'oracle'              => $this->oracle->metadata(),
			'oracleIdentitySha256' => OracleRenderer::identity_sha256( $this->oracle->metadata() ),
			'limits'              => $this->limits,
			'maxInputBytes'       => $this->max_input_bytes,
			'maxKeepPerSignature' => $this->max_keep_per_signature,
			'processTimeoutMs'    => $this->process_timeout_ms,
			'memoryLimit'         => $this->memory_limit,
			'checks'              => $this->checks,
			'fullSamplePercent'   => $this->full_sample_percent,
			'requireUtf8'         => $this->require_utf8,
			'retainAll'           => $this->retain_all,
			'workerScript'        => realpath( $this->worker_script ) ?: $this->worker_script,
			'inputSemantics'      => 'raw cc-analyzer response body; no charset transcoding',
			'ccAnalyzer'          => array(
				'version'    => getenv( 'CC_ANALYZER_VERSION' ) ?: null,
				'crawl'      => getenv( 'CC_ANALYZER_CRAWL' ) ?: null,
				'invocation' => getenv( 'CC_ANALYZER_INVOCATION' ) ?: null,
				'coordinator' => $this->coordinator_provenance,
			),
		);
		$this->configuration_hash = hash( 'sha256', json_encode( self::canonicalize( $configuration ), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) );
		$configuration['configHash'] = $this->configuration_hash;

		$path = $this->output_dir . '/configuration.json';
		$lock = fopen( $this->output_dir . '/.configuration.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new \RuntimeException( 'Could not lock Common Crawl configuration.' );
		}
		try {
			$existing = read_json_file( $path );
			if ( is_array( $existing ) ) {
				if ( ! hash_equals( (string) ( $existing['configHash'] ?? '' ), $this->configuration_hash ) ) {
					throw new \RuntimeException( 'Common Crawl output directory configuration mismatch; use a new run directory or identical settings.' );
				}
				$this->run_id = (string) ( $existing['runId'] ?? $this->run_id );
				return;
			}
			$configuration['createdAt'] = gmdate( 'c' );
			write_json_file_atomic( $path, $configuration );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function run_metadata(): array {
		return array(
			'runId'      => $this->run_id,
			'configHash' => $this->configuration_hash,
			'outputDir'  => $this->output_dir,
			'coordinator' => $this->coordinator_provenance,
		);
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private function document_metadata( object $document ): array {
		return array(
			'recordId'         => (string) ( $document->recordId ?? '' ),
			'targetUri'        => (string) ( $document->targetUri ?? '' ),
			'responseCode'     => is_int( $document->responseCode ?? null ) ? $document->responseCode : null,
			'contentType'      => (string) ( $document->contentType ?? '' ),
			'transportCharset' => is_string( $document->transportCharset ?? null ) ? $document->transportCharset : null,
			'inputStateKey'    => (string) ( $document->inputStateKey ?? '' ),
			'rangeStart'       => is_int( $document->rangeStart ?? null ) ? $document->rangeStart : null,
			'rangeLength'      => is_int( $document->rangeLength ?? null ) ? $document->rangeLength : null,
		);
	}

	private static function compact_process( array $process ): array {
		return array(
			'code'       => $process['code'] ?? null,
			'timedOut'   => $process['timedOut'] ?? false,
			'durationMs' => $process['durationMs'] ?? null,
			'stderrTail' => substr( (string) ( $process['stderr'] ?? '' ), -2000 ),
			'stdoutTruncated' => $process['stdoutTruncated'] ?? false,
			'stderrTruncated' => $process['stderrTruncated'] ?? false,
			'logPath'    => $process['logPath'] ?? null,
			'processGroupIsolated' => $process['processGroupIsolated'] ?? false,
			'processGroupCleanupFailed' => $process['processGroupCleanupFailed'] ?? false,
		);
	}

	private static function seed_for_record( string $record_id, string $input_sha1 ): int {
		return 1 + ( hexdec( substr( hash( 'sha256', $record_id . "\0" . $input_sha1 ), 0, 7 ) ) % 2147483646 );
	}

	private function checks_for_seed( int $seed ): string {
		if ( 'sampled' !== $this->checks ) {
			return $this->checks;
		}
		$bucket = hexdec( substr( hash( 'sha256', 'commoncrawl-full-checks:' . $seed ), 0, 8 ) ) % 100;
		return $bucket < $this->full_sample_percent ? 'full' : 'baseline';
	}

	private static function elapsed_ms( int $started_at ): int {
		return (int) round( max( 0, hrtime( true ) - $started_at ) / 1000000 );
	}

	private static function coordinator_provenance_from_environment(): ?array {
		$variables = array(
			'id'                   => 'HTML_API_CC_COORDINATOR_ID',
			'batchName'            => 'HTML_API_CC_BATCH_NAME',
			'cachePath'            => 'HTML_API_CC_CACHE_PATH',
			'batchManifestPath'    => 'HTML_API_CC_BATCH_MANIFEST_PATH',
			'batchManifestSha256'  => 'HTML_API_CC_BATCH_MANIFEST_SHA256',
			'corpusFingerprint'    => 'HTML_API_CC_CORPUS_FINGERPRINT',
			'documentCount'        => 'HTML_API_CC_DOCUMENT_COUNT',
			'crawlId'              => 'HTML_API_CC_CRAWL_ID',
			'pharPath'             => 'HTML_API_CC_PHAR_PATH',
			'pharSha256'           => 'HTML_API_CC_PHAR_SHA256',
			'invocationBase64'     => 'HTML_API_CC_INVOCATION_BASE64',
			'callbackSha256'       => 'HTML_API_CC_CALLBACK_SHA256',
			'workerSha256'         => 'HTML_API_CC_WORKER_SHA256',
			'repositoryCommit'     => 'HTML_API_CC_REPOSITORY_COMMIT',
			'repositoryDirty'      => 'HTML_API_CC_REPOSITORY_DIRTY',
			'repositoryCodeSha256' => 'HTML_API_CC_REPOSITORY_CODE_SHA256',
			'repositoryStateSha256'=> 'HTML_API_CC_REPOSITORY_STATE_SHA256',
			'runtimeSha256'        => 'HTML_API_CC_RUNTIME_SHA256',
			'trustBundleSha256'    => 'HTML_API_CC_TRUST_BUNDLE_SHA256',
		);
		$values = array();
		$present = 0;
		foreach ( $variables as $key => $name ) {
			$value = getenv( $name );
			if ( false !== $value && '' !== $value ) {
				++$present;
				$values[ $key ] = (string) $value;
		}
		}
		if ( 0 === $present ) {
			return null;
		}
		if ( count( $variables ) !== $present ) {
			throw new \InvalidArgumentException( 'Coordinator provenance environment must be supplied as one complete block.' );
		}

		foreach ( array( 'id', 'batchName' ) as $key ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $values[ $key ] ) ) {
				throw new \InvalidArgumentException( "Coordinator provenance {$key} is invalid." );
			}
		}
		foreach ( array( 'batchManifestSha256', 'corpusFingerprint', 'pharSha256', 'callbackSha256', 'workerSha256', 'repositoryCodeSha256', 'repositoryStateSha256', 'runtimeSha256', 'trustBundleSha256' ) as $key ) {
			if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $values[ $key ] ) ) {
				throw new \InvalidArgumentException( "Coordinator provenance {$key} must be a lowercase SHA-256 digest." );
			}
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $values['repositoryCommit'] ) ) {
			throw new \InvalidArgumentException( 'Coordinator repository commit must be a lowercase Git object ID.' );
		}
		if ( ! in_array( $values['repositoryDirty'], array( '0', '1' ), true ) ) {
			throw new \InvalidArgumentException( 'Coordinator repository dirty state must be 0 or 1.' );
		}
		$document_count = filter_var( $values['documentCount'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $document_count ) {
			throw new \InvalidArgumentException( 'Coordinator document count must be a positive integer.' );
		}
		foreach ( array( 'crawlId', 'cachePath', 'batchManifestPath', 'pharPath' ) as $key ) {
			if ( '' === trim( $values[ $key ] ) ) {
				throw new \InvalidArgumentException( "Coordinator provenance {$key} must be non-empty." );
			}
		}
		$cache_path = realpath( $values['cachePath'] );
		$manifest_path = realpath( $values['batchManifestPath'] );
		$phar_path = realpath( $values['pharPath'] );
		if ( false === $cache_path || ! is_file( $cache_path ) || false === $manifest_path || ! is_file( $manifest_path ) || false === $phar_path || ! is_file( $phar_path ) ) {
			throw new \RuntimeException( 'Coordinator provenance paths must resolve to regular files.' );
		}
		if ( ! hash_equals( $values['pharSha256'], hash_file( 'sha256', $phar_path ) ?: '' ) ) {
			throw new \RuntimeException( 'Coordinator PHAR hash changed before Common Crawl callback initialization.' );
		}
		$callback_path = dirname( __DIR__ ) . '/commoncrawl-analysis.php';
		if ( ! hash_equals( $values['callbackSha256'], hash_file( 'sha256', $callback_path ) ?: '' ) ) {
			throw new \RuntimeException( 'Coordinator callback hash does not match commoncrawl-analysis.php.' );
		}
		$worker_path = realpath( self::environment_string( 'HTML_API_CC_WORKER_SCRIPT', dirname( __DIR__ ) . '/worker.php' ) );
		if ( false === $worker_path || ! hash_equals( $values['workerSha256'], hash_file( 'sha256', $worker_path ) ?: '' ) ) {
			throw new \RuntimeException( 'Coordinator Worker hash does not match the selected Worker script.' );
		}
		if ( 'batch' !== getenv( 'CC_ANALYZER_MODE' ) || $values['batchName'] !== getenv( 'CC_ANALYZER_BATCH' ) ) {
			throw new \RuntimeException( 'Coordinator provenance requires the matching cc-analyzer batch callback mode.' );
		}
		$cc_manifest = realpath( (string) getenv( 'CC_ANALYZER_MANIFEST' ) );
		if ( false === $cc_manifest || $cache_path !== $cc_manifest ) {
			throw new \RuntimeException( 'Coordinator cache path does not match CC_ANALYZER_MANIFEST.' );
		}

		$invocation_json = base64_decode( $values['invocationBase64'], true );
		if ( false === $invocation_json ) {
			throw new \InvalidArgumentException( 'Coordinator invocation is not valid base64.' );
		}
		$invocation = StrictJsonParser::decode( $invocation_json );
		if ( ! is_array( $invocation ) || array_keys( $invocation ) !== range( 0, count( $invocation ) - 1 ) || empty( $invocation ) ) {
			throw new \InvalidArgumentException( 'Coordinator invocation must be a non-empty JSON string list.' );
		}
		foreach ( $invocation as $argument ) {
			if ( ! is_string( $argument ) || '' === $argument || false !== strpos( $argument, "\0" ) ) {
				throw new \InvalidArgumentException( 'Coordinator invocation contains an invalid argument.' );
			}
		}

		return array(
			'id'                   => $values['id'],
			'batchName'            => $values['batchName'],
			'cachePath'            => $cache_path,
			'batchManifestPath'    => $manifest_path,
			'batchManifestSha256'  => $values['batchManifestSha256'],
			'corpusFingerprint'    => $values['corpusFingerprint'],
			'documentCount'        => (int) $document_count,
			'crawlId'              => $values['crawlId'],
			'pharPath'             => $phar_path,
			'pharSha256'           => $values['pharSha256'],
			'invocation'           => $invocation,
			'callbackSha256'       => $values['callbackSha256'],
			'workerSha256'         => $values['workerSha256'],
			'repositoryCommit'     => $values['repositoryCommit'],
			'repositoryDirty'      => '1' === $values['repositoryDirty'],
			'repositoryCodeSha256' => $values['repositoryCodeSha256'],
			'repositoryStateSha256'=> $values['repositoryStateSha256'],
			'runtimeSha256'        => $values['runtimeSha256'],
			'trustBundleSha256'    => $values['trustBundleSha256'],
		);
	}

	private static function environment_string( string $name, string $default ): string {
		$value = getenv( $name );
		return false === $value || '' === $value ? $default : (string) $value;
	}

	private static function environment_int( string $name, int $default, int $minimum ): int {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			return $default;
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $parsed || $parsed < $minimum ) {
			throw new \InvalidArgumentException( "{$name} must be an integer greater than or equal to {$minimum}." );
		}
		return (int) $parsed;
	}

	private static function environment_bool( string $name, bool $default ): bool {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			return $default;
		}
		$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $parsed ) {
			throw new \InvalidArgumentException( "{$name} must be a boolean value." );
		}
		return $parsed;
	}
}
