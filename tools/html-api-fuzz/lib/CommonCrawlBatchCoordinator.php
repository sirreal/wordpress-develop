<?php
namespace HtmlApiFuzz;

/** Runs one immutable cc-analyzer batch through every required oracle. */
final class CommonCrawlBatchCoordinator {
	private const REQUIRED_KINDS = array(
		OracleRenderer::KIND_LEXBOR_SOURCE,
		OracleRenderer::KIND_HTML5EVER_SOURCE,
		OracleRenderer::KIND_CHROME_CDP,
	);

	private const REQUIRED_TOOL_DEPENDENCIES = array(
		'tools/html-api-fuzz/commoncrawl-analysis.php',
		'tools/html-api-fuzz/commoncrawl-batch.php',
		'tools/html-api-fuzz/oracle-process-supervisor.php',
		'tools/html-api-fuzz/process-group.php',
		'tools/html-api-fuzz/worker.php',
		'tools/html-api-fuzz/lib/autoload.php',
		'tools/html-api-fuzz/lib/ChromeOracleRenderer.php',
		'tools/html-api-fuzz/lib/CommonCrawlBatchCoordinator.php',
		'tools/html-api-fuzz/lib/CommonCrawlRunner.php',
		'tools/html-api-fuzz/lib/Corpus.php',
		'tools/html-api-fuzz/lib/Generator.php',
		'tools/html-api-fuzz/lib/HtmlApiBootstrap.php',
		'tools/html-api-fuzz/lib/Mutator.php',
		'tools/html-api-fuzz/lib/OracleFinding.php',
		'tools/html-api-fuzz/lib/OracleRenderer.php',
		'tools/html-api-fuzz/lib/Prng.php',
		'tools/html-api-fuzz/lib/ResultStore.php',
		'tools/html-api-fuzz/lib/Signature.php',
		'tools/html-api-fuzz/lib/Support.php',
		'tools/html-api-fuzz/lib/TagInvariants.php',
		'tools/html-api-fuzz/lib/TreeRenderer.php',
		'tools/html-api-fuzz/lib/Worker.php',
		'tools/html-api-fuzz/lib/wp-stubs.php',
	);

	private const WORDPRESS_DEPENDENCIES = array(
		'src/wp-includes/compat.php',
		'src/wp-includes/compat-utf8.php',
		'src/wp-includes/utf8.php',
		'src/wp-includes/class-wp-token-map.php',
		'src/wp-includes/html-api/html5-named-character-references.php',
		'src/wp-includes/html-api/class-wp-html-attribute-token.php',
		'src/wp-includes/html-api/class-wp-html-span.php',
		'src/wp-includes/html-api/class-wp-html-doctype-info.php',
		'src/wp-includes/html-api/class-wp-html-text-replacement.php',
		'src/wp-includes/html-api/class-wp-html-decoder.php',
		'src/wp-includes/html-api/class-wp-html-tag-processor.php',
		'src/wp-includes/html-api/class-wp-html-unsupported-exception.php',
		'src/wp-includes/html-api/class-wp-html-active-formatting-elements.php',
		'src/wp-includes/html-api/class-wp-html-open-elements.php',
		'src/wp-includes/html-api/class-wp-html-token.php',
		'src/wp-includes/html-api/class-wp-html-stack-event.php',
		'src/wp-includes/html-api/class-wp-html-processor-state.php',
		'src/wp-includes/html-api/class-wp-html-processor.php',
	);

	private string $phar_path;
	private string $workspace;
	private string $batch_name;
	private string $output_dir;
	private string $coordinator_id;
	private string $callback_path;
	private string $worker_path;
	private string $coordinator_script;
	private int $batch_timeout_ms;
	private int $probe_timeout_ms;
	private int $document_timeout_ms;
	private int $oracle_timeout_ms;
	private int $chrome_startup_timeout_ms;
	private string $checks;
	private string $memory_limit;
	private bool $retain_all;
	private bool $require_utf8;
	private array $limits;
	private array $oracle_options;
	private array $base_environment;

	public static function executed_code_pathspecs(): array {
		return array_merge( array( 'tools/html-api-fuzz' ), self::WORDPRESS_DEPENDENCIES );
	}

	private function __construct( array $options ) {
		if ( ! class_exists( '\SQLite3' ) || ! function_exists( 'posix_kill' ) || ! function_exists( 'posix_setsid' ) || ! function_exists( 'pcntl_exec' ) ) {
			throw new \RuntimeException( 'Shared-corpus coordination requires SQLite3, POSIX, and PCNTL.' );
		}
		self::validate_options( $options );
		$this->phar_path = self::existing_file_option( $options, 'cc-analyzer' );
		$this->workspace = self::existing_directory_option( $options, 'workspace' );
		$this->batch_name = self::required_string_option( $options, 'batch' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $this->batch_name ) ) {
			throw new \InvalidArgumentException( 'Batch name contains unsafe characters.' );
		}
		$this->output_dir = self::new_path_option( $options, 'output-dir' );
		$this->coordinator_id = option_string( $options, 'coordinator-id', 'shared-' . $this->batch_name . '-' . timestamp() );
		if ( null === $this->coordinator_id || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $this->coordinator_id ) ) {
			throw new \InvalidArgumentException( 'Coordinator ID contains unsafe characters.' );
		}

		$this->batch_timeout_ms = self::required_positive_option( $options, 'batch-timeout-ms' );
		$this->document_timeout_ms = self::positive_option( $options, 'process-timeout-ms', 90000 );
		$this->oracle_timeout_ms = self::positive_option( $options, 'oracle-timeout-ms', 10000 );
		$this->chrome_startup_timeout_ms = self::positive_option( $options, 'chrome-startup-timeout-ms', ChromeOracleRenderer::DEFAULT_STARTUP_TIMEOUT_MS );
		if ( $this->chrome_startup_timeout_ms > PHP_INT_MAX - $this->oracle_timeout_ms || $this->chrome_startup_timeout_ms + $this->oracle_timeout_ms > PHP_INT_MAX - 20000 ) {
			throw new \OverflowException( 'Oracle probe timeout exceeds the platform integer range.' );
		}
		$probe_budget = $this->chrome_startup_timeout_ms + $this->oracle_timeout_ms + 20000;
		$this->probe_timeout_ms = max( 60000, $probe_budget );
		$this->checks = option_string( $options, 'checks', 'baseline' );
		if ( ! in_array( $this->checks, array( 'baseline', 'full', 'sampled' ), true ) ) {
			throw new \InvalidArgumentException( '--checks must be baseline, full, or sampled.' );
		}
		$this->memory_limit = option_string( $options, 'memory-limit', '256M' );
		if ( null === $this->memory_limit || 1 !== preg_match( '/^[1-9][0-9]*[KMG]?$/i', $this->memory_limit ) ) {
			throw new \InvalidArgumentException( '--memory-limit must be a positive PHP limit such as 256M.' );
		}
		$this->retain_all = self::strict_bool_option( $options, 'retain-all', false );
		$this->require_utf8 = self::strict_bool_option( $options, 'require-utf8', false );
		$this->limits = array(
			'maxInputBytes' => self::positive_option( $options, 'max-input-bytes', 2097152, 0 ),
			'maxTokens'     => self::positive_option( $options, 'max-tokens', 50000 ),
			'maxNodes'      => self::positive_option( $options, 'max-nodes', 50000 ),
			'maxDepth'      => self::positive_option( $options, 'max-depth', 512 ),
			'maxTreeBytes'  => self::positive_option( $options, 'max-tree-bytes', 16777216 ),
		);

		$lexbor = self::resolved_executable( option_string( $options, 'lexbor-oracle-bin', repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle' ), 'Lexbor oracle' );
		$html5ever = self::resolved_executable( option_string( $options, 'html5ever-oracle-bin', repo_root() . '/tools/html-api-fuzz/oracles/html5ever/build/html5ever-tree-oracle' ), 'html5ever oracle' );
		$chrome_script = self::resolved_file( option_string( $options, 'chrome-oracle-script', repo_root() . '/tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js' ), 'Chrome oracle script' );
		$chrome_executable = self::resolved_executable( self::required_string_option( $options, 'chrome-executable' ), 'Chrome executable' );
		$node = self::resolved_executable( self::required_string_option( $options, 'node-bin' ), 'Node executable' );
		$this->oracle_options = array(
			OracleRenderer::KIND_LEXBOR_SOURCE => array(
				'dom-oracle' => OracleRenderer::KIND_LEXBOR_SOURCE,
				'lexbor-oracle-bin' => $lexbor,
				'oracle-timeout-ms' => (string) $this->oracle_timeout_ms,
			),
			OracleRenderer::KIND_HTML5EVER_SOURCE => array(
				'dom-oracle' => OracleRenderer::KIND_HTML5EVER_SOURCE,
				'html5ever-oracle-bin' => $html5ever,
				'oracle-timeout-ms' => (string) $this->oracle_timeout_ms,
			),
			OracleRenderer::KIND_CHROME_CDP => array(
				'dom-oracle' => OracleRenderer::KIND_CHROME_CDP,
				'chrome-oracle-script' => $chrome_script,
				'chrome-executable' => $chrome_executable,
				'node-bin' => $node,
				'oracle-timeout-ms' => (string) $this->oracle_timeout_ms,
				'chrome-startup-timeout-ms' => (string) $this->chrome_startup_timeout_ms,
			),
		);

		$this->callback_path = self::resolved_file( dirname( __DIR__ ) . '/commoncrawl-analysis.php', 'Common Crawl callback' );
		$this->worker_path = self::resolved_file( dirname( __DIR__ ) . '/worker.php', 'Worker' );
		$this->coordinator_script = self::resolved_file( dirname( __DIR__ ) . '/commoncrawl-batch.php', 'Coordinator script' );
		$php_binary = self::resolved_executable( PHP_BINARY, 'PHP executable' );
		$path_parts = array_values( array_unique( array( dirname( $php_binary ), dirname( $node ), '/usr/bin', '/bin' ) ) );
		$this->base_environment = array(
			'PATH'   => implode( PATH_SEPARATOR, $path_parts ),
			'HOME'   => $this->output_dir . '/sandbox-home',
			'TMPDIR' => $this->output_dir . '/sandbox-tmp',
			'LANG'   => 'C',
			'LC_ALL' => 'C',
			'TZ'     => 'UTC',
			// macOS injects this into a child when it is absent; pin it instead.
			'__CF_USER_TEXT_ENCODING' => sprintf( '0x%X:0x0:0x0', posix_getuid() ),
		);
	}

	public static function run( array $options ): array {
		$coordinator = new self( $options );
		$coordinator->claim_output();
		try {
			$result = $coordinator->execute();
			$payload = self::strict_json_file( $coordinator->output_dir . '/shared-corpus.payload.json' );
			if ( self::canonical_json( $result ) !== self::canonical_json( $payload ) ) {
				throw new \RuntimeException( 'Sealed shared-corpus payload changed before publication.' );
			}
			write_json_file_atomic( $coordinator->output_dir . '/coordinator-state.json', array(
				'kind' => 'html-api-commoncrawl-coordinator-state',
				'status' => 'sealed',
				'coordinatorId' => $coordinator->coordinator_id,
				'sharedCorpus' => $coordinator->output_dir . '/shared-corpus.json',
				'completionMarker' => $coordinator->output_dir . '/.complete',
			) );
			write_json_file_atomic( $coordinator->output_dir . '/shared-corpus.json', $result );
			foreach ( self::REQUIRED_KINDS as $kind ) {
				write_file_atomic( $coordinator->output_dir . '/' . $kind . '/.complete', "complete\n" );
			}
			write_json_file_atomic( $coordinator->output_dir . '/coordinator-state.json', array(
				'kind' => 'html-api-commoncrawl-coordinator-state',
				'status' => 'published',
				'coordinatorId' => $coordinator->coordinator_id,
				'sharedCorpus' => $coordinator->output_dir . '/shared-corpus.json',
				'completionMarker' => $coordinator->output_dir . '/.complete',
			) );
			// This is the sole authoritative completion marker and the final
			// fallible operation. No earlier artifact means the root completed.
			write_file_atomic( $coordinator->output_dir . '/.complete', "complete\n" );
			return $result;
		} catch ( \Throwable $error ) {
			$success_paths = array(
				$coordinator->output_dir . '/.complete',
				$coordinator->output_dir . '/shared-corpus.json',
			);
			foreach ( self::REQUIRED_KINDS as $kind ) {
				$success_paths[] = $coordinator->output_dir . '/' . $kind . '/.complete';
			}
			foreach ( $success_paths as $success_path ) {
				if ( is_file( $success_path ) || is_link( $success_path ) ) {
					@unlink( $success_path );
				}
			}
			write_json_file_atomic( $coordinator->output_dir . '/coordinator-state.json', array(
				'kind' => 'html-api-commoncrawl-coordinator-state',
				'status' => 'failed',
				'coordinatorId' => $coordinator->coordinator_id,
				'error' => $error->getMessage(),
				'throwable' => get_class( $error ),
			) );
			throw $error;
		}
	}

	private function claim_output(): void {
		if ( file_exists( $this->output_dir ) || is_link( $this->output_dir ) ) {
			throw new \RuntimeException( 'Coordinator output directory already exists; use a fresh path.' );
		}
		if ( ! mkdir( $this->output_dir, 0700, false ) ) {
			throw new \RuntimeException( 'Could not create coordinator output directory.' );
		}
		ensure_dir( $this->base_environment['HOME'] );
		ensure_dir( $this->base_environment['TMPDIR'] );
		write_json_file_atomic( $this->output_dir . '/coordinator-state.json', array(
			'kind' => 'html-api-commoncrawl-coordinator-state',
			'status' => 'running',
			'coordinatorId' => $this->coordinator_id,
			'batchName' => $this->batch_name,
		) );
	}

	private function execute(): array {
		$preflight_dir = $this->output_dir . '/preflight';
		ensure_dir( $preflight_dir );
		$entry_phar = self::file_identity( $this->phar_path );
		$info_process = $this->run_analyzer_command(
			array( 'batch', 'info', $this->batch_name, '--output', 'json' ),
			min( $this->batch_timeout_ms, 60000 ),
			$preflight_dir . '/batch-info.stdout',
			$preflight_dir . '/batch-info.stderr'
		);
		if ( self::canonical_json( $entry_phar ) !== self::canonical_json( self::file_identity( $this->phar_path ) ) ) {
			throw new \RuntimeException( 'cc-analyzer PHAR changed during batch info.' );
		}
		$info = self::validate_batch_info( self::decode_process_json( $info_process, 'batch info' ), $this->batch_name );
		$cache_path = realpath( $info['cachePath'] );
		if ( false === $cache_path || ! is_file( $cache_path ) ) {
			throw new \RuntimeException( 'Batch info cache path is not a readable regular file.' );
		}
		$verify_process = $this->run_analyzer_command(
			array( 'batch', 'verify', $this->batch_name, '--output', 'json' ),
			min( $this->batch_timeout_ms, 60000 ),
			$preflight_dir . '/batch-verify.stdout',
			$preflight_dir . '/batch-verify.stderr'
		);
		if ( self::canonical_json( $entry_phar ) !== self::canonical_json( self::file_identity( $this->phar_path ) ) ) {
			throw new \RuntimeException( 'cc-analyzer PHAR changed during batch verify.' );
		}
		$verification = self::validate_batch_verification( self::decode_process_json( $verify_process, 'batch verify' ), $this->batch_name, $info['documentCount'] );
		$manifest = self::load_batch_manifest( $cache_path, $info );
		write_json_file_atomic( $this->output_dir . '/batch-manifest.json', $manifest );

		$invocation = array( PHP_BINARY, $this->phar_path, '--workspace', $this->workspace, 'batch', 'run', $this->batch_name, $this->callback_path, '--output', 'json' );
		$initial_trust = $this->capture_trust_bundle( $this->base_environment, $cache_path, $info, $manifest, 'during initial trust capture' );
		write_json_file_atomic( $this->output_dir . '/preflight.json', array(
			'kind' => 'html-api-commoncrawl-preflight',
			'coordinatorId' => $this->coordinator_id,
			'info' => $info,
			'verification' => $verification,
			'manifestSha256' => $manifest['batchManifestSha256'],
			'corpusFingerprint' => $manifest['corpusFingerprint'],
			'invocation' => $invocation,
			'baseEnvironment' => $this->base_environment,
			'trust' => $initial_trust,
		) );

		$runs = array();
		foreach ( self::REQUIRED_KINDS as $kind ) {
			$run_dir = $this->output_dir . '/' . $kind;
			if ( file_exists( $run_dir ) || is_link( $run_dir ) ) {
				throw new \RuntimeException( "Oracle output directory already exists: {$kind}" );
			}
			ensure_dir( $run_dir );
			$environment = $this->run_environment( $kind, $run_dir, $cache_path, $manifest, $invocation, $initial_trust );
			$environment_evidence = $this->write_environment_evidence( $run_dir, $environment );
			$before = $this->capture_trust_bundle( $environment, $cache_path, $info, $manifest, "immediately before {$kind}" );
			self::assert_same_trust( $initial_trust, $before, "before {$kind}" );

			$process = $this->run_analyzer_command(
				array( 'batch', 'run', $this->batch_name, $this->callback_path, '--output', 'json' ),
				$this->batch_timeout_ms,
				$run_dir . '/batch-run.stdout',
				$run_dir . '/batch-run.stderr',
				$environment
			);
			$batch_result = self::validate_batch_run_result(
				self::decode_process_json( $process, "{$kind} batch run" ),
				$manifest['batch'],
				$this->callback_path,
				$initial_trust['callback']['sha256']
			);
			$after = $this->capture_trust_bundle( $environment, $cache_path, $info, $manifest, "immediately after {$kind}" );
			self::assert_same_trust( $initial_trust, $after, "after {$kind}" );
			$provenance = $this->provenance_from_environment( $environment );
			$summary = self::verify_run_output( $run_dir, $manifest, $environment['HTML_API_CC_RUN_ID'], $kind, $initial_trust['oracles'][ $kind ]['identitySha256'], $provenance, $environment );
			$runs[ $kind ] = array(
				'kind' => $kind,
				'outputDir' => $run_dir,
				'batchResult' => $batch_result,
				'process' => self::compact_process( $process ),
				'summary' => $summary,
				'provenance' => $provenance,
				'environment' => $environment,
				'environmentEvidence' => $environment_evidence,
			);
		}

		$final_trust = $this->capture_trust_bundle( $this->base_environment, $cache_path, $info, $manifest, 'during final trust capture' );
		self::assert_same_trust( $initial_trust, $final_trust, 'before final sealing' );
		$disk_manifest = self::strict_json_file( $this->output_dir . '/batch-manifest.json' );
		self::assert_same_manifest( $manifest, $disk_manifest, 'in on-disk batch-manifest.json before sealing' );

		foreach ( $runs as $kind => &$run ) {
			$final_batch_result = self::validate_batch_run_result(
				self::strict_json_file( $run['outputDir'] . '/batch-run.stdout' ),
				$manifest['batch'],
				$this->callback_path,
				$initial_trust['callback']['sha256']
			);
			if ( self::canonical_json( $run['batchResult'] ) !== self::canonical_json( $final_batch_result ) ) {
				throw new \RuntimeException( "{$kind} batch stdout changed after verification." );
			}
			$run['summary'] = self::verify_run_output(
				$run['outputDir'],
				$manifest,
				$run['environment']['HTML_API_CC_RUN_ID'],
				$kind,
				$initial_trust['oracles'][ $kind ]['identitySha256'],
				$run['provenance'],
				$run['environment']
			);
			$environment_identity = self::file_identity( $run['environmentEvidence']['path'] );
			if ( ! hash_equals( $run['environmentEvidence']['sha256'], $environment_identity['sha256'] ) ) {
				throw new \RuntimeException( "{$kind} replacement environment file changed after publication." );
			}
			$seal = self::seal_directory( $run['outputDir'], array( 'run-seal.json', '.complete' ) );
			write_json_file_atomic( $run['outputDir'] . '/run-seal.json', $seal );
			$run['sealSha256'] = self::file_identity( $run['outputDir'] . '/run-seal.json' )['sha256'];
		}
		unset( $run );
		$vectors = array_map( static fn ( array $run ): array => $run['summary']['vector'], $runs );
		$first_vector = reset( $vectors );
		foreach ( $vectors as $kind => $vector ) {
			if ( self::canonical_json( $first_vector ) !== self::canonical_json( $vector ) ) {
				throw new \RuntimeException( "Shared corpus vector differs for {$kind}." );
			}
		}
		self::assert_same_manifest( $manifest, self::load_batch_manifest( $cache_path, $info ), 'in the final pre-seal cache read' );

		$result = array(
			'schemaVersion' => 1,
			'kind' => 'html-api-commoncrawl-shared-corpus',
			'coordinatorId' => $this->coordinator_id,
			'batchName' => $this->batch_name,
			'crawlId' => $manifest['batch']['crawlId'],
			'documentCount' => $manifest['batch']['documentCount'],
			'batchManifestSha256' => $manifest['batchManifestSha256'],
			'corpusFingerprint' => $manifest['corpusFingerprint'],
			'invocation' => $invocation,
			'trust' => $initial_trust,
			'orderedCorpus' => $first_vector,
			'runs' => $runs,
		);
		write_json_file_atomic( $this->output_dir . '/shared-corpus.payload.json', $result );
		$root_seal = self::seal_directory( $this->output_dir, array( 'coordinator-seal.json', 'coordinator-state.json', '.complete' ) );
		write_json_file_atomic( $this->output_dir . '/coordinator-seal.json', $root_seal );
		return $result;
	}

	private function run_analyzer_command( array $arguments, int $timeout_ms, string $stdout_path, string $stderr_path, ?array $environment = null ): array {
		$process = run_php_process(
			array_merge( array( $this->phar_path, '--workspace', $this->workspace ), $arguments ),
			repo_root(),
			$timeout_ms,
			null,
			1048576,
			true,
			$environment ?? $this->base_environment,
			true,
			$stdout_path,
			$stderr_path
		);
		self::assert_process_success( $process, 'cc-analyzer command' );
		return $process;
	}

	private function capture_trust_bundle( array $oracle_environment, string $cache_path, array $info, array $expected_manifest, string $when ): array {
		$manifest_before = self::load_batch_manifest( $cache_path, $info );
		self::assert_same_manifest( $expected_manifest, $manifest_before, "before {$when}" );
		$files_before = $this->trust_file_identities();
		$runtime_before = $this->runtime_probe( $this->base_environment );
		$code_before = self::repository_code_identity( $this->git_probe( $this->base_environment ) );
		$oracles = array();
		foreach ( self::REQUIRED_KINDS as $kind ) {
			$probe = $this->oracle_probe( $kind, $oracle_environment );
			$oracles[ $kind ] = array(
				'metadata' => $probe['metadata'],
				'identitySha256' => OracleRenderer::identity_sha256( $probe['metadata'] ),
				'replayOptions' => $probe['replayOptions'],
				'workerArgs' => $probe['workerArgs'],
			);
		}
		$runtime_after = $this->runtime_probe( $this->base_environment );
		$code_after = self::repository_code_identity( $this->git_probe( $this->base_environment ) );
		$files_after = $this->trust_file_identities();
		$manifest_after = self::load_batch_manifest( $cache_path, $info );
		self::assert_same_manifest( $expected_manifest, $manifest_after, "after {$when}" );
		if ( self::canonical_json( $files_before ) !== self::canonical_json( $files_after ) || self::canonical_json( $runtime_before ) !== self::canonical_json( $runtime_after ) || self::canonical_json( $code_before ) !== self::canonical_json( $code_after ) ) {
			throw new \RuntimeException( "Trust inputs changed {$when}." );
		}
		$bundle = array(
			'phpBinary' => $files_after['phpBinary'],
			'phar' => $files_after['phar'],
			'callback' => $files_after['callback'],
			'worker' => $files_after['worker'],
			'oracleTrustFiles' => $files_after['oracleTrustFiles'],
			'runtime' => $runtime_after,
			'repository' => $code_after,
			'oracles' => $oracles,
			'batchManifestSha256' => $manifest_after['batchManifestSha256'],
			'corpusFingerprint' => $manifest_after['corpusFingerprint'],
		);
		$bundle['trustBundleSha256'] = hash( 'sha256', self::canonical_json( $bundle ) );
		return $bundle;
	}

	private function trust_file_identities(): array {
		$lexbor_binary = $this->oracle_options[ OracleRenderer::KIND_LEXBOR_SOURCE ]['lexbor-oracle-bin'];
		$html5ever_binary = $this->oracle_options[ OracleRenderer::KIND_HTML5EVER_SOURCE ]['html5ever-oracle-bin'];
		$html5ever_root = dirname( dirname( $html5ever_binary ) );
		$chrome_root = repo_root() . '/tools/html-api-fuzz/oracles/chrome';
		$paths = array(
			'lexborBinary' => $lexbor_binary,
			'lexborBuildManifest' => dirname( $lexbor_binary ) . '/build-manifest.json',
			'html5everBinary' => $html5ever_binary,
			'html5everBuildManifest' => dirname( $html5ever_binary ) . '/build-manifest.json',
			'html5everCargoToml' => $html5ever_root . '/Cargo.toml',
			'html5everCargoLock' => $html5ever_root . '/Cargo.lock',
			'html5everRustToolchain' => $html5ever_root . '/rust-toolchain.toml',
			'html5everSource' => $html5ever_root . '/src/main.rs',
			'chromeScript' => $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['chrome-oracle-script'],
			'chromeExecutable' => $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['chrome-executable'],
			'nodeExecutable' => $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['node-bin'],
			'chromeVersion' => $chrome_root . '/VERSION',
			'chromeArchiveChecksums' => $chrome_root . '/SHA256SUMS',
			'chromeExecutableChecksums' => $chrome_root . '/EXECUTABLE_SHA256SUMS',
			'fragmentContexts' => repo_root() . '/tools/html-api-fuzz/oracles/fragment-contexts.json',
		);
		$identities = array();
		foreach ( $paths as $name => $path ) {
			$identities[ $name ] = self::file_identity( $path );
		}
		return array(
			'phpBinary' => self::file_identity( PHP_BINARY ),
			'phar' => self::file_identity( $this->phar_path ),
			'callback' => self::file_identity( $this->callback_path ),
			'worker' => self::file_identity( $this->worker_path ),
			'oracleTrustFiles' => $identities,
		);
	}

	private function write_environment_evidence( string $run_dir, array $environment ): array {
		ksort( $environment );
		$evidence = array(
			'kind' => 'html-api-commoncrawl-replacement-environment',
			'environment' => $environment,
			'environmentSha256' => hash( 'sha256', self::canonical_json( $environment ) ),
		);
		$path = $run_dir . '/replacement-environment.json';
		write_json_file_atomic( $path, $evidence );
		return array(
			'path' => $path,
			'sha256' => self::file_identity( $path )['sha256'],
			'environmentSha256' => $evidence['environmentSha256'],
		);
	}

	private function runtime_probe( array $environment ): array {
		$process = run_php_process(
			array( $this->coordinator_script, '--internal-runtime-probe' ),
			repo_root(),
			min( $this->probe_timeout_ms, 60000 ),
			null,
			1048576,
			true,
			$environment,
			true
		);
		$probe = self::decode_process_json( $process, 'sanitized PHP runtime probe' );
		self::assert_exact_keys( $probe, array( 'phpVersion', 'phpBinary', 'loadedIni', 'scannedIni', 'extensions', 'environment' ), 'runtime probe' );
		self::assert_environment_echo( $probe, $environment, 'runtime probe' );
		if ( ! is_string( $probe['phpVersion'] ) || '' === $probe['phpVersion'] || ! is_string( $probe['phpBinary'] ) || '' === $probe['phpBinary'] || ( null !== $probe['loadedIni'] && ( ! is_string( $probe['loadedIni'] ) || '' === $probe['loadedIni'] ) ) || ! is_array( $probe['scannedIni'] ) || ! is_array( $probe['extensions'] ) ) {
			throw new \RuntimeException( 'Runtime probe returned invalid types.' );
		}
		foreach ( array_merge( $probe['scannedIni'], $probe['extensions'] ) as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				throw new \RuntimeException( 'Runtime probe returned an invalid ini or extension entry.' );
			}
		}
		if ( realpath( (string) $probe['phpBinary'] ) !== realpath( PHP_BINARY ) ) {
			throw new \RuntimeException( 'Runtime probe used a different PHP binary.' );
		}
		$ini = array();
		$ini_paths = array_merge( is_string( $probe['loadedIni'] ) && '' !== $probe['loadedIni'] ? array( $probe['loadedIni'] ) : array(), is_array( $probe['scannedIni'] ) ? $probe['scannedIni'] : array() );
		foreach ( $ini_paths as $path ) {
			$ini[] = self::file_identity( self::resolved_file( is_string( $path ) ? $path : null, 'PHP ini file' ) );
		}
		$identity = array(
			'phpVersion' => $probe['phpVersion'],
			'phpBinary' => realpath( PHP_BINARY ),
			'iniFiles' => $ini,
			'extensions' => $probe['extensions'],
		);
		$identity['runtimeSha256'] = hash( 'sha256', self::canonical_json( $identity ) );
		return $identity;
	}

	private function git_probe( array $environment ): array {
		$process = run_php_process(
			array( $this->coordinator_script, '--internal-git-probe' ),
			repo_root(),
			60000,
			null,
			16777216,
			true,
			$environment,
			true
		);
		$probe = self::decode_process_json( $process, 'sanitized Git probe' );
		self::assert_exact_keys( $probe, array( 'commit', 'branch', 'statusBase64', 'tracked', 'environment' ), 'Git probe' );
		self::assert_environment_echo( $probe, $environment, 'Git probe' );
		if ( ! is_string( $probe['commit'] ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $probe['commit'] ) || ( null !== $probe['branch'] && ! is_string( $probe['branch'] ) ) || ! is_string( $probe['statusBase64'] ) || ! is_array( $probe['tracked'] ) ) {
			throw new \RuntimeException( 'Git probe returned invalid repository identity.' );
		}
		return $probe;
	}

	private function oracle_probe( string $kind, array $environment ): array {
		$encoded = base64_encode( self::canonical_json( $this->oracle_options[ $kind ] ) );
		$process = run_php_process(
			array( $this->coordinator_script, '--internal-oracle-probe', $encoded ),
			repo_root(),
			$this->probe_timeout_ms,
			null,
			1048576,
			true,
			$environment,
			true
		);
		$probe = self::decode_process_json( $process, "{$kind} oracle probe" );
		self::assert_exact_keys( $probe, array( 'metadata', 'replayOptions', 'workerArgs', 'environment' ), "{$kind} oracle probe" );
		self::assert_environment_echo( $probe, $environment, "{$kind} oracle probe" );
		if ( ! is_array( $probe['metadata'] ?? null ) || true !== ( $probe['metadata']['available'] ?? false ) || $kind !== ( $probe['metadata']['kind'] ?? null ) ) {
			throw new \RuntimeException( "{$kind} oracle preflight is unavailable or inconsistent." );
		}
		if ( ! is_array( $probe['replayOptions'] ?? null ) || ! is_array( $probe['workerArgs'] ?? null ) ) {
			throw new \RuntimeException( "{$kind} oracle probe omitted replay or Worker options." );
		}
		return $probe;
	}

	private function run_environment( string $kind, string $run_dir, string $cache_path, array $manifest, array $invocation, array $trust ): array {
		$environment = $this->base_environment;
		$environment['CC_ANALYZER_OUTPUT_DIR'] = $run_dir;
		$environment['HTML_API_CC_RUN_ID'] = $this->coordinator_id . '-' . $kind;
		$environment['HTML_API_CC_ORACLE'] = $kind;
		$environment['HTML_API_CC_EXPECT_ORACLE_IDENTITY_SHA256'] = $trust['oracles'][ $kind ]['identitySha256'];
		$environment['HTML_API_CC_ORACLE_TIMEOUT_MS'] = (string) $this->oracle_timeout_ms;
		$environment['HTML_API_CC_CHROME_STARTUP_TIMEOUT_MS'] = (string) $this->chrome_startup_timeout_ms;
		$environment['HTML_API_CC_PROCESS_TIMEOUT_MS'] = (string) $this->document_timeout_ms;
		$environment['HTML_API_CC_CHECKS'] = $this->checks;
		$environment['HTML_API_CC_FULL_SAMPLE_PERCENT'] = '0';
		$environment['HTML_API_CC_MEMORY_LIMIT'] = $this->memory_limit;
		$environment['HTML_API_CC_MAX_INPUT_BYTES'] = (string) $this->limits['maxInputBytes'];
		$environment['HTML_API_CC_MAX_TOKENS'] = (string) $this->limits['maxTokens'];
		$environment['HTML_API_CC_MAX_NODES'] = (string) $this->limits['maxNodes'];
		$environment['HTML_API_CC_MAX_DEPTH'] = (string) $this->limits['maxDepth'];
		$environment['HTML_API_CC_MAX_TREE_BYTES'] = (string) $this->limits['maxTreeBytes'];
		$environment['HTML_API_CC_MAX_KEEP_PER_SIGNATURE'] = '3';
		$environment['HTML_API_CC_REQUIRE_UTF8'] = $this->require_utf8 ? '1' : '0';
		$environment['HTML_API_CC_RETAIN_ALL'] = $this->retain_all ? '1' : '0';
		$environment['HTML_API_CC_WORKER_SCRIPT'] = $this->worker_path;
		$environment['HTML_API_FUZZ_LEXBOR_ORACLE'] = $this->oracle_options[ OracleRenderer::KIND_LEXBOR_SOURCE ]['lexbor-oracle-bin'];
		$environment['HTML_API_FUZZ_HTML5EVER_ORACLE'] = $this->oracle_options[ OracleRenderer::KIND_HTML5EVER_SOURCE ]['html5ever-oracle-bin'];
		$environment['HTML_API_FUZZ_CHROME_ORACLE'] = $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['chrome-oracle-script'];
		$environment['HTML_API_FUZZ_CHROME_EXECUTABLE'] = $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['chrome-executable'];
		$environment['HTML_API_FUZZ_NODE_BIN'] = $this->oracle_options[ OracleRenderer::KIND_CHROME_CDP ]['node-bin'];
		$environment['HTML_API_CC_COORDINATOR_ID'] = $this->coordinator_id;
		$environment['HTML_API_CC_BATCH_NAME'] = $this->batch_name;
		$environment['HTML_API_CC_CACHE_PATH'] = $cache_path;
		$environment['HTML_API_CC_BATCH_MANIFEST_PATH'] = $this->output_dir . '/batch-manifest.json';
		$environment['HTML_API_CC_BATCH_MANIFEST_SHA256'] = $manifest['batchManifestSha256'];
		$environment['HTML_API_CC_CORPUS_FINGERPRINT'] = $manifest['corpusFingerprint'];
		$environment['HTML_API_CC_DOCUMENT_COUNT'] = (string) $manifest['batch']['documentCount'];
		$environment['HTML_API_CC_CRAWL_ID'] = $manifest['batch']['crawlId'];
		$environment['HTML_API_CC_PHAR_PATH'] = $this->phar_path;
		$environment['HTML_API_CC_PHAR_SHA256'] = $trust['phar']['sha256'];
		$environment['HTML_API_CC_INVOCATION_BASE64'] = base64_encode( self::canonical_json( $invocation ) );
		$environment['HTML_API_CC_CALLBACK_SHA256'] = $trust['callback']['sha256'];
		$environment['HTML_API_CC_WORKER_SHA256'] = $trust['worker']['sha256'];
		$environment['HTML_API_CC_REPOSITORY_COMMIT'] = $trust['repository']['commit'];
		$environment['HTML_API_CC_REPOSITORY_DIRTY'] = $trust['repository']['dirty'] ? '1' : '0';
		$environment['HTML_API_CC_REPOSITORY_CODE_SHA256'] = $trust['repository']['codeSha256'];
		$environment['HTML_API_CC_REPOSITORY_STATE_SHA256'] = $trust['repository']['stateSha256'];
		$environment['HTML_API_CC_RUNTIME_SHA256'] = $trust['runtime']['runtimeSha256'];
		$environment['HTML_API_CC_TRUST_BUNDLE_SHA256'] = $trust['trustBundleSha256'];
		ksort( $environment );
		return $environment;
	}

	private function provenance_from_environment( array $environment ): array {
		$invocation = StrictJsonParser::decode( base64_decode( $environment['HTML_API_CC_INVOCATION_BASE64'], true ) );
		return array(
			'id' => $environment['HTML_API_CC_COORDINATOR_ID'],
			'batchName' => $environment['HTML_API_CC_BATCH_NAME'],
			'cachePath' => $environment['HTML_API_CC_CACHE_PATH'],
			'batchManifestPath' => $environment['HTML_API_CC_BATCH_MANIFEST_PATH'],
			'batchManifestSha256' => $environment['HTML_API_CC_BATCH_MANIFEST_SHA256'],
			'corpusFingerprint' => $environment['HTML_API_CC_CORPUS_FINGERPRINT'],
			'documentCount' => (int) $environment['HTML_API_CC_DOCUMENT_COUNT'],
			'crawlId' => $environment['HTML_API_CC_CRAWL_ID'],
			'pharPath' => $environment['HTML_API_CC_PHAR_PATH'],
			'pharSha256' => $environment['HTML_API_CC_PHAR_SHA256'],
			'invocation' => $invocation,
			'callbackSha256' => $environment['HTML_API_CC_CALLBACK_SHA256'],
			'workerSha256' => $environment['HTML_API_CC_WORKER_SHA256'],
			'repositoryCommit' => $environment['HTML_API_CC_REPOSITORY_COMMIT'],
			'repositoryDirty' => '1' === $environment['HTML_API_CC_REPOSITORY_DIRTY'],
			'repositoryCodeSha256' => $environment['HTML_API_CC_REPOSITORY_CODE_SHA256'],
			'repositoryStateSha256' => $environment['HTML_API_CC_REPOSITORY_STATE_SHA256'],
			'runtimeSha256' => $environment['HTML_API_CC_RUNTIME_SHA256'],
			'trustBundleSha256' => $environment['HTML_API_CC_TRUST_BUNDLE_SHA256'],
		);
	}

	public static function load_batch_manifest( string $cache_path, array $info ): array {
		$db = new \SQLite3( $cache_path, SQLITE3_OPEN_READONLY );
		$db->busyTimeout( 5000 );
		try {
			self::assert_table_columns( $db, 'document_batches', array( 'name', 'source_mode', 'crawl_selection', 'crawl_id', 'url_pattern', 'requested_limit', 'state_path', 'cache_path', 'status', 'created_at', 'updated_at', 'completed_at' ) );
			self::assert_table_columns( $db, 'document_batch_documents', array( 'batch_name', 'sequence', 'input_state_key', 'input_url', 'record_id', 'target_uri', 'response_code', 'byte_length', 'checksum', 'range_start', 'range_length', 'cached_at' ) );
			self::assert_table_columns( $db, 'cached_documents', array( 'input_state_key', 'input_url', 'record_id', 'target_uri', 'response_code', 'content_type', 'transport_charset', 'response_headers_json', 'body', 'byte_length', 'range_start', 'range_length', 'fetched_at', 'last_used_at' ) );
			$statement = $db->prepare( 'SELECT * FROM document_batches WHERE name = :name LIMIT 1' );
			$statement->bindValue( ':name', $info['name'], SQLITE3_TEXT );
			$batch_row = $statement->execute()->fetchArray( SQLITE3_ASSOC );
			if ( ! is_array( $batch_row ) ) {
				throw new \RuntimeException( 'Named batch disappeared from the document cache.' );
			}
			foreach ( array( 'name', 'source_mode', 'crawl_selection', 'crawl_id', 'url_pattern', 'state_path', 'cache_path', 'status', 'created_at', 'updated_at' ) as $field ) {
				if ( ! is_string( $batch_row[ $field ] ?? null ) ) {
					throw new \RuntimeException( "Batch database metadata {$field} has an invalid type." );
				}
			}
			if ( ! is_int( $batch_row['requested_limit'] ?? null ) || $batch_row['requested_limit'] < 1 || ! is_string( $batch_row['completed_at'] ?? null ) || '' === $batch_row['completed_at'] ) {
				throw new \RuntimeException( 'Batch database limit or completion metadata is invalid.' );
			}
			$batch = array(
				'name' => $batch_row['name'],
				'sourceMode' => $batch_row['source_mode'],
				'crawlSelection' => $batch_row['crawl_selection'],
				'crawlId' => $batch_row['crawl_id'],
				'urlPattern' => $batch_row['url_pattern'],
				'requestedLimit' => $batch_row['requested_limit'],
				'statePath' => $batch_row['state_path'],
				'cachePath' => realpath( $batch_row['cache_path'] ) ?: $batch_row['cache_path'],
				'status' => $batch_row['status'],
				'documentCount' => (int) $info['documentCount'],
				'byteCount' => (int) $info['byteCount'],
				'createdAt' => $batch_row['created_at'],
				'updatedAt' => $batch_row['updated_at'],
				'completedAt' => $batch_row['completed_at'],
			);
			foreach ( array( 'name', 'sourceMode', 'crawlSelection', 'crawlId', 'urlPattern', 'requestedLimit', 'statePath', 'status', 'documentCount', 'byteCount', 'createdAt', 'updatedAt', 'completedAt' ) as $key ) {
				if ( $batch[ $key ] !== $info[ $key ] ) {
					throw new \RuntimeException( "Batch database metadata {$key} does not match batch info." );
				}
			}
			if ( 'ready' !== $batch['status'] || realpath( $cache_path ) !== realpath( $batch['cachePath'] ) || realpath( $info['cachePath'] ) !== realpath( $batch['cachePath'] ) ) {
				throw new \RuntimeException( 'Batch database cache path does not match batch info.' );
			}

			$query = $db->prepare(
				'SELECT d.*, c.input_url AS cache_input_url, c.target_uri AS cache_target_uri,
					c.response_code AS cache_response_code, c.content_type, c.transport_charset,
					c.body, c.byte_length AS cache_byte_length, c.range_start AS cache_range_start,
					c.range_length AS cache_range_length
				FROM document_batch_documents d
				LEFT JOIN cached_documents c
					ON c.input_state_key = d.input_state_key AND c.record_id = d.record_id
				WHERE d.batch_name = :name ORDER BY d.sequence ASC'
			);
			$query->bindValue( ':name', $info['name'], SQLITE3_TEXT );
			$rows = $query->execute();
			$documents = array();
			$seen_identity = array();
			$seen_vector = array();
			$byte_count = 0;
			while ( $row = $rows->fetchArray( SQLITE3_ASSOC ) ) {
				$sequence = count( $documents ) + 1;
				if ( ! is_int( $row['sequence'] ?? null ) || $sequence !== $row['sequence'] ) {
					throw new \RuntimeException( 'Batch document sequence is duplicated or non-contiguous.' );
				}
				foreach ( array( 'input_state_key', 'input_url', 'record_id', 'target_uri', 'checksum', 'cached_at' ) as $field ) {
					if ( ! is_string( $row[ $field ] ) || '' === $row[ $field ] ) {
						throw new \RuntimeException( "Batch manifest field {$field} is empty or invalid." );
					}
				}
				if ( ! is_string( $row['body'] ?? null ) ) {
					throw new \RuntimeException( "Batch cache body is missing at sequence {$sequence}." );
				}
				foreach ( array( 'response_code', 'cache_response_code' ) as $field ) {
					if ( ! is_int( $row[ $field ] ?? null ) || $row[ $field ] < 100 || $row[ $field ] > 599 ) {
						throw new \RuntimeException( "Batch HTTP response metadata is invalid at sequence {$sequence}." );
					}
				}
				foreach ( array( 'byte_length', 'cache_byte_length' ) as $field ) {
					if ( ! is_int( $row[ $field ] ?? null ) || $row[ $field ] < 0 ) {
						throw new \RuntimeException( "Batch byte-length metadata is invalid at sequence {$sequence}." );
					}
				}
				foreach ( array( 'range_start', 'cache_range_start' ) as $field ) {
					if ( null !== $row[ $field ] && ( ! is_int( $row[ $field ] ) || $row[ $field ] < 0 ) ) {
						throw new \RuntimeException( "Batch range-start metadata is invalid at sequence {$sequence}." );
					}
				}
				foreach ( array( 'range_length', 'cache_range_length' ) as $field ) {
					if ( null !== $row[ $field ] && ( ! is_int( $row[ $field ] ) || $row[ $field ] < 1 ) ) {
						throw new \RuntimeException( "Batch range-length metadata is invalid at sequence {$sequence}." );
					}
				}
				if ( ! is_string( $row['content_type'] ?? null ) || 1 !== preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\/[!#$%&\'*+.^_`|~0-9A-Za-z-]+(?:[ \t]*;[^\r\n]*)?$/D', $row['content_type'] ) ) {
					throw new \RuntimeException( "Batch content-type metadata is invalid at sequence {$sequence}." );
				}
				if ( null !== $row['transport_charset'] && ( ! is_string( $row['transport_charset'] ) || '' === trim( $row['transport_charset'] ) || preg_match( '/[\x00-\x20\x7f]/', $row['transport_charset'] ) ) ) {
					throw new \RuntimeException( "Batch transport-charset metadata is invalid at sequence {$sequence}." );
				}
				$length = strlen( $row['body'] );
				$sha256 = hash( 'sha256', $row['body'] );
				if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $row['checksum'] ) || ! hash_equals( $row['checksum'], $sha256 ) || $length !== $row['byte_length'] || $length !== $row['cache_byte_length'] ) {
					throw new \RuntimeException( "Batch cache body identity mismatch at sequence {$sequence}." );
				}
				foreach ( array( 'input_url' => 'cache_input_url', 'target_uri' => 'cache_target_uri', 'response_code' => 'cache_response_code', 'range_start' => 'cache_range_start', 'range_length' => 'cache_range_length' ) as $manifest_field => $cache_field ) {
					if ( $row[ $manifest_field ] !== $row[ $cache_field ] ) {
						throw new \RuntimeException( "Batch/cache metadata differs for {$manifest_field} at sequence {$sequence}." );
					}
				}
				$range_start = $row['range_start'];
				$range_length = $row['range_length'];
				if ( ( null === $range_start ) !== ( null === $range_length ) || ( null !== $range_start && ( $range_start < 0 || $range_length < 1 ) ) ) {
					throw new \RuntimeException( "Batch range metadata is invalid at sequence {$sequence}." );
				}
				$identity_key = $row['input_state_key'] . "\0" . $row['record_id'];
				$vector_key = $row['record_id'] . "\0" . $sha256 . "\0" . $length;
				if ( isset( $seen_identity[ $identity_key ] ) || isset( $seen_vector[ $vector_key ] ) ) {
					throw new \RuntimeException( "Batch contains a duplicate document at sequence {$sequence}." );
				}
				$seen_identity[ $identity_key ] = true;
				$seen_vector[ $vector_key ] = true;
				$documents[] = array(
					'sequence' => $sequence,
					'inputStateKey' => $row['input_state_key'],
					'inputUrl' => $row['input_url'],
					'recordId' => $row['record_id'],
					'targetUri' => $row['target_uri'],
					'responseCode' => $row['response_code'],
					'contentType' => $row['content_type'],
					'transportCharset' => $row['transport_charset'],
					'inputLength' => $length,
					'inputSha256' => $sha256,
					'rangeStart' => $range_start,
					'rangeLength' => $range_length,
					'cachedAt' => $row['cached_at'],
				);
				$byte_count += $length;
			}
			if ( count( $documents ) !== $batch['documentCount'] || $byte_count !== $batch['byteCount'] || 0 === count( $documents ) ) {
				throw new \RuntimeException( 'Batch manifest count or byte total is incomplete.' );
			}
			$stable = array( 'batch' => $batch, 'documents' => $documents );
			$vector = array_map( static fn ( array $document ): array => array( 'recordId' => $document['recordId'], 'inputSha256' => $document['inputSha256'], 'inputLength' => $document['inputLength'] ), $documents );
			return array(
				'schemaVersion' => 1,
				'kind' => 'html-api-commoncrawl-batch-manifest',
				'batch' => $batch,
				'documents' => $documents,
				'batchManifestSha256' => hash( 'sha256', self::canonical_json( $stable ) ),
				'corpusFingerprint' => hash( 'sha256', self::canonical_json( $vector ) ),
			);
		} finally {
			$db->close();
		}
	}

	public static function verify_run_output( string $run_dir, array $manifest, string $run_id, string $kind, string $identity_sha256, array $provenance, ?array $expected_environment = null ): array {
		$summary_path = $run_dir . '/commoncrawl-summary.ndjson';
		$text = @file_get_contents( $summary_path );
		if ( false === $text || '' === $text || "\n" !== substr( $text, -1 ) ) {
			throw new \RuntimeException( "{$kind} summary is missing or not newline-terminated." );
		}
		$lines = explode( "\n", substr( $text, 0, -1 ) );
		if ( count( $lines ) !== count( $manifest['documents'] ) ) {
			throw new \RuntimeException( "{$kind} summary is partial or contains extra documents." );
		}
		$vector = array();
		$statuses = array();
		$config_hash = null;
		$seen = array();
		foreach ( $lines as $index => $line ) {
			if ( '' === $line ) {
				throw new \RuntimeException( "{$kind} summary contains a blank record." );
			}
			$record = StrictJsonParser::decode( $line );
			if ( ! is_array( $record ) ) {
				throw new \RuntimeException( "{$kind} summary record is not an object." );
			}
			$expected = $manifest['documents'][ $index ];
			$common = is_array( $record['commonCrawl'] ?? null ) ? $record['commonCrawl'] : array();
			foreach ( array( 'recordId', 'inputStateKey', 'targetUri', 'rangeStart', 'rangeLength' ) as $field ) {
				if ( ( $common[ $field ] ?? null ) !== $expected[ $field ] ) {
					throw new \RuntimeException( "{$kind} summary metadata/order differs at sequence " . ( $index + 1 ) . "." );
				}
			}
			if ( ( $record['inputSha256'] ?? null ) !== $expected['inputSha256'] || ( $record['inputLength'] ?? null ) !== $expected['inputLength'] || ( $common['inputSha256'] ?? null ) !== $expected['inputSha256'] || ( $common['byteLength'] ?? null ) !== $expected['inputLength'] ) {
				throw new \RuntimeException( "{$kind} summary input identity differs at sequence " . ( $index + 1 ) . "." );
			}
			$key = $expected['recordId'] . "\0" . $expected['inputSha256'] . "\0" . $expected['inputLength'];
			if ( isset( $seen[ $key ] ) ) {
				throw new \RuntimeException( "{$kind} summary contains a duplicate document." );
			}
			$seen[ $key ] = true;
			if ( $run_id !== ( $record['runId'] ?? null ) || self::canonical_json( $provenance ) !== self::canonical_json( $record['coordinator'] ?? null ) ) {
				throw new \RuntimeException( "{$kind} summary run/coordinator provenance differs." );
			}
			if ( ( $record['repo']['commit'] ?? null ) !== $provenance['repositoryCommit'] || ( $record['repo']['dirty'] ?? null ) !== $provenance['repositoryDirty'] ) {
				throw new \RuntimeException( "{$kind} summary repository provenance differs." );
			}
			if ( null === $config_hash ) {
				$config_hash = $record['configHash'] ?? null;
			} elseif ( $config_hash !== ( $record['configHash'] ?? null ) ) {
				throw new \RuntimeException( "{$kind} summary mixes configurations." );
			}
			if ( ! is_array( $record['oracle'] ?? null ) || ! hash_equals( $identity_sha256, OracleRenderer::identity_sha256( $record['oracle'] ) ) ) {
				throw new \RuntimeException( "{$kind} summary oracle identity differs." );
			}
			if ( in_array( $record['failureClass'] ?? null, array( 'commoncrawl-callback-error', 'worker-input-identity-drift', 'oracle-identity-drift' ), true ) ) {
				throw new \RuntimeException( "{$kind} run contains coordinator-critical identity/callback failure." );
			}
			$status = (string) ( $record['status'] ?? 'unknown' );
			$statuses[ $status ] = 1 + (int) ( $statuses[ $status ] ?? 0 );
			$vector[] = array( 'recordId' => $expected['recordId'], 'inputSha256' => $expected['inputSha256'], 'inputLength' => $expected['inputLength'] );
		}
		$configuration = self::strict_json_file( $run_dir . '/configuration.json' );
		self::assert_exact_keys( $configuration, array( 'kind', 'runId', 'repo', 'phpVersion', 'oracle', 'oracleIdentitySha256', 'limits', 'maxInputBytes', 'maxKeepPerSignature', 'processTimeoutMs', 'memoryLimit', 'checks', 'fullSamplePercent', 'requireUtf8', 'retainAll', 'workerScript', 'inputSemantics', 'ccAnalyzer', 'configHash', 'createdAt' ), "{$kind} configuration" );
		$claimed_config_hash = $configuration['configHash'] ?? null;
		$hash_material = $configuration;
		unset( $hash_material['configHash'], $hash_material['createdAt'] );
		$computed_config_hash = hash( 'sha256', self::canonical_json( $hash_material ) );
		if ( ! is_string( $claimed_config_hash ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $claimed_config_hash ) || ! hash_equals( $computed_config_hash, $claimed_config_hash ) || $run_id !== ( $configuration['runId'] ?? null ) || $config_hash !== $claimed_config_hash || self::canonical_json( $provenance ) !== self::canonical_json( $configuration['ccAnalyzer']['coordinator'] ?? null ) ) {
			throw new \RuntimeException( "{$kind} configuration does not match its summaries." );
		}
		if ( 'html-api-commoncrawl-configuration' !== $configuration['kind'] || ! is_string( $configuration['createdAt'] ) || '' === $configuration['createdAt'] || 'raw cc-analyzer response body; no charset transcoding' !== $configuration['inputSemantics'] || ! is_array( $configuration['repo'] ) || ! is_array( $configuration['ccAnalyzer'] ) ) {
			throw new \RuntimeException( "{$kind} configuration identity fields are invalid." );
		}
		if ( ( $configuration['repo']['commit'] ?? null ) !== $provenance['repositoryCommit'] || ( $configuration['repo']['dirty'] ?? null ) !== $provenance['repositoryDirty'] ) {
			throw new \RuntimeException( "{$kind} configuration repository provenance differs." );
		}
		if ( ! is_array( $configuration['oracle'] ?? null ) || ! hash_equals( $identity_sha256, OracleRenderer::identity_sha256( $configuration['oracle'] ) ) || ! is_string( $configuration['oracleIdentitySha256'] ) || ! hash_equals( $identity_sha256, $configuration['oracleIdentitySha256'] ) ) {
			throw new \RuntimeException( "{$kind} configuration oracle identity differs." );
		}
		if ( null !== $expected_environment ) {
			ksort( $expected_environment );
			$environment_evidence = self::strict_json_file( $run_dir . '/replacement-environment.json' );
			self::assert_exact_keys( $environment_evidence, array( 'kind', 'environment', 'environmentSha256' ), "{$kind} replacement environment" );
			$environment_sha256 = hash( 'sha256', self::canonical_json( $expected_environment ) );
			if ( 'html-api-commoncrawl-replacement-environment' !== $environment_evidence['kind'] || self::canonical_json( $expected_environment ) !== self::canonical_json( $environment_evidence['environment'] ?? null ) || ! is_string( $environment_evidence['environmentSha256'] ) || ! hash_equals( $environment_sha256, $environment_evidence['environmentSha256'] ) ) {
				throw new \RuntimeException( "{$kind} replacement environment evidence differs." );
			}
			$expected_configuration = array(
				'limits' => array(
					'maxTokens' => (int) $expected_environment['HTML_API_CC_MAX_TOKENS'],
					'maxNodes' => (int) $expected_environment['HTML_API_CC_MAX_NODES'],
					'maxDepth' => (int) $expected_environment['HTML_API_CC_MAX_DEPTH'],
					'maxTreeBytes' => (int) $expected_environment['HTML_API_CC_MAX_TREE_BYTES'],
				),
				'maxInputBytes' => (int) $expected_environment['HTML_API_CC_MAX_INPUT_BYTES'],
				'maxKeepPerSignature' => (int) $expected_environment['HTML_API_CC_MAX_KEEP_PER_SIGNATURE'],
				'processTimeoutMs' => (int) $expected_environment['HTML_API_CC_PROCESS_TIMEOUT_MS'],
				'memoryLimit' => $expected_environment['HTML_API_CC_MEMORY_LIMIT'],
				'checks' => $expected_environment['HTML_API_CC_CHECKS'],
				'fullSamplePercent' => (int) $expected_environment['HTML_API_CC_FULL_SAMPLE_PERCENT'],
				'requireUtf8' => '1' === $expected_environment['HTML_API_CC_REQUIRE_UTF8'],
				'retainAll' => '1' === $expected_environment['HTML_API_CC_RETAIN_ALL'],
				'workerScript' => realpath( $expected_environment['HTML_API_CC_WORKER_SCRIPT'] ),
			);
			foreach ( $expected_configuration as $field => $expected ) {
				if ( $expected !== ( $configuration[ $field ] ?? null ) ) {
					throw new \RuntimeException( "{$kind} configuration field {$field} differs from the replacement environment." );
				}
			}
			if ( PHP_VERSION !== ( $configuration['phpVersion'] ?? null ) ) {
				throw new \RuntimeException( "{$kind} configuration PHP version differs." );
			}
		}
		return array(
			'count' => count( $vector ),
			'configHash' => $config_hash,
			'statuses' => $statuses,
			'vector' => $vector,
			'corpusFingerprint' => hash( 'sha256', self::canonical_json( $vector ) ),
		);
	}

	private static function repository_code_identity( array $git ): array {
		$status = base64_decode( (string) $git['statusBase64'], true );
		if ( false === $status ) {
			throw new \RuntimeException( 'Git status probe is not valid base64.' );
		}
		$tracked = array();
		$required = array_fill_keys( array_merge( self::REQUIRED_TOOL_DEPENDENCIES, self::WORDPRESS_DEPENDENCIES ), false );
		foreach ( $git['tracked'] as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) || ! is_string( $entry['mode'] ?? null ) ) {
				throw new \RuntimeException( 'Git tracked-file probe is malformed.' );
			}
			$path = $entry['path'];
			if ( 0 !== strpos( $path, 'tools/html-api-fuzz/' ) && ! array_key_exists( $path, $required ) ) {
				throw new \RuntimeException( 'Git probe returned an unexpected executed-code path.' );
			}
			$absolute = repo_root() . '/' . $path;
			if ( is_link( $absolute ) || ! is_file( $absolute ) ) {
				throw new \RuntimeException( "Executed repository code is missing or symlinked: {$path}" );
			}
			if ( isset( $required[ $path ] ) ) {
				$required[ $path ] = true;
			}
			$identity = self::file_identity( $absolute );
			$tracked[] = array(
				'path' => $path,
				'mode' => $entry['mode'],
				'actualMode' => $identity['mode'],
				'bytes' => $identity['bytes'],
				'sha256' => $identity['sha256'],
			);
		}
		foreach ( $required as $path => $present ) {
			if ( ! $present ) {
				throw new \RuntimeException( "Required executed-code dependency is not tracked: {$path}" );
			}
		}
		usort( $tracked, static fn ( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
		return array(
			'commit' => $git['commit'],
			'branch' => is_string( $git['branch'] ) ? $git['branch'] : null,
			'dirty' => '' !== $status,
			'stateSha256' => hash( 'sha256', $status ),
			'codeSha256' => hash( 'sha256', self::canonical_json( $tracked ) ),
			'trackedFiles' => $tracked,
		);
	}

	private static function validate_batch_info( $value, string $batch_name ): array {
		$keys = array( 'name', 'sourceMode', 'crawlSelection', 'crawlId', 'urlPattern', 'requestedLimit', 'statePath', 'cachePath', 'status', 'documentCount', 'byteCount', 'runCount', 'createdAt', 'updatedAt', 'completedAt' );
		self::assert_exact_keys( $value, $keys, 'batch info' );
		foreach ( array( 'name', 'sourceMode', 'crawlSelection', 'crawlId', 'statePath', 'cachePath', 'status', 'createdAt', 'updatedAt' ) as $key ) {
			if ( ! is_string( $value[ $key ] ) || '' === $value[ $key ] ) {
				throw new \RuntimeException( "Batch info {$key} is invalid." );
			}
		}
		if ( ! is_string( $value['urlPattern'] ) || ! is_string( $value['completedAt'] ) || '' === $value['completedAt'] ) {
			throw new \RuntimeException( 'Batch info URL pattern or completion time is invalid.' );
		}
		foreach ( array( 'requestedLimit', 'documentCount' ) as $key ) {
			if ( ! is_int( $value[ $key ] ) || $value[ $key ] < 1 ) {
				throw new \RuntimeException( "Batch info {$key} is invalid." );
			}
		}
		foreach ( array( 'byteCount', 'runCount' ) as $key ) {
			if ( ! is_int( $value[ $key ] ) || $value[ $key ] < 0 ) {
				throw new \RuntimeException( "Batch info {$key} is invalid." );
			}
		}
		if ( $batch_name !== $value['name'] || 'ready' !== $value['status'] ) {
			throw new \RuntimeException( 'Batch is not the requested ready batch.' );
		}
		return $value;
	}

	private static function validate_batch_verification( $value, string $batch_name, int $count ): array {
		self::assert_exact_keys( $value, array( 'batchName', 'documentCount', 'missingCount', 'missingDocuments' ), 'batch verification' );
		if ( ! is_string( $value['batchName'] ) || ! is_int( $value['documentCount'] ) || ! is_int( $value['missingCount'] ) || ! is_array( $value['missingDocuments'] ) || $batch_name !== $value['batchName'] || $count !== $value['documentCount'] || 0 !== $value['missingCount'] || array() !== $value['missingDocuments'] ) {
			throw new \RuntimeException( 'Batch verification reports missing or inconsistent cached documents.' );
		}
		return $value;
	}

	private static function validate_batch_run_result( $value, array $expected_batch, string $callback_path, string $callback_sha256 ): array {
		self::assert_exact_keys( $value, array( 'batch', 'run', 'documentsAnalyzed' ), 'batch run result' );
		$batch = self::validate_batch_info( $value['batch'], $expected_batch['name'] );
		foreach ( $expected_batch as $key => $expected ) {
			if ( ! array_key_exists( $key, $batch ) ) {
				throw new \RuntimeException( "Batch run result omitted batch metadata {$key}." );
			}
			if ( 'cachePath' === $key ) {
				if ( false === realpath( $expected ) || realpath( $expected ) !== realpath( $batch[ $key ] ) ) {
					throw new \RuntimeException( 'Batch run result changed batch metadata cachePath.' );
				}
				continue;
			}
			if ( $expected !== $batch[ $key ] ) {
				throw new \RuntimeException( "Batch run result changed batch metadata {$key}." );
			}
		}
		self::assert_exact_keys( $value['run'], array( 'batchName', 'runId', 'analysisScript', 'analysisScriptHash', 'documentsAnalyzed', 'startedAt', 'completedAt' ), 'batch run record' );
		$run = $value['run'];
		$count = $expected_batch['documentCount'];
		if (
			! is_int( $value['documentsAnalyzed'] ) ||
			! is_string( $run['batchName'] ) ||
			! is_int( $run['runId'] ) || $run['runId'] < 1 ||
			! is_string( $run['analysisScript'] ) ||
			! is_string( $run['analysisScriptHash'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $run['analysisScriptHash'] ) ||
			! is_int( $run['documentsAnalyzed'] ) ||
			! is_string( $run['startedAt'] ) || '' === $run['startedAt'] ||
			! is_string( $run['completedAt'] ) || '' === $run['completedAt'] ||
			$count !== $value['documentsAnalyzed'] ||
			$expected_batch['name'] !== $run['batchName'] ||
			$callback_path !== $run['analysisScript'] ||
			$count !== $run['documentsAnalyzed'] ||
			! hash_equals( $callback_sha256, $run['analysisScriptHash'] )
		) {
			throw new \RuntimeException( 'Batch run result is partial or inconsistent.' );
		}
		return $value;
	}

	private static function assert_table_columns( \SQLite3 $db, string $table, array $expected ): void {
		$result = $db->query( "PRAGMA table_info({$table})" );
		$columns = array();
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$columns[] = $row['name'];
		}
		if ( $columns !== $expected ) {
			throw new \RuntimeException( "Unsupported cc-analyzer SQLite schema for {$table}." );
		}
	}

	private static function assert_process_success( array $process, string $label ): void {
		if ( 0 !== ( $process['code'] ?? null ) || true === ( $process['timedOut'] ?? false ) || true === ( $process['stdoutTruncated'] ?? false ) || true === ( $process['stderrTruncated'] ?? false ) || true === ( $process['processGroupCleanupFailed'] ?? false ) ) {
			throw new \RuntimeException( "{$label} failed, timed out, truncated output, or leaked a process group." );
		}
	}

	private static function decode_process_json( array $process, string $label ) {
		self::assert_process_success( $process, $label );
		$stdout = (string) ( $process['stdout'] ?? '' );
		if ( '' === $stdout || "\n" !== substr( $stdout, -1 ) ) {
			throw new \RuntimeException( "{$label} did not emit one newline-terminated JSON value." );
		}
		return StrictJsonParser::decode( substr( $stdout, 0, -1 ) );
	}

	private static function assert_environment_echo( array &$probe, array $environment, string $label ): void {
		$actual = $probe['environment'] ?? null;
		$expected = $environment;
		ksort( $expected );
		if ( ! is_array( $actual ) || self::canonical_json( $expected ) !== self::canonical_json( $actual ) ) {
			throw new \RuntimeException( "{$label} did not receive the exact sanitized replacement environment." );
		}
		unset( $probe['environment'] );
	}

	private static function assert_same_trust( array $expected, array $actual, string $when ): void {
		if ( self::canonical_json( $expected ) !== self::canonical_json( $actual ) ) {
			throw new \RuntimeException( "Global trust bundle changed {$when}." );
		}
	}

	private static function assert_same_manifest( array $expected, array $actual, string $when ): void {
		if ( self::canonical_json( $expected ) !== self::canonical_json( $actual ) ) {
			throw new \RuntimeException( "Cached batch corpus changed {$when}." );
		}
	}

	private static function assert_exact_keys( $value, array $expected, string $label ): void {
		if ( ! is_array( $value ) ) {
			throw new \RuntimeException( "{$label} must be a JSON object." );
		}
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new \RuntimeException( "{$label} has an unexpected JSON schema." );
		}
	}

	private static function compact_process( array $process ): array {
		return array(
			'command' => $process['command'] ?? null,
			'code' => $process['code'] ?? null,
			'durationMs' => $process['durationMs'] ?? null,
			'stdoutLogPath' => $process['stdoutLogPath'] ?? null,
			'stderrLogPath' => $process['stderrLogPath'] ?? null,
			'processGroupIsolated' => $process['processGroupIsolated'] ?? false,
			'processGroupCleanupFailed' => $process['processGroupCleanupFailed'] ?? false,
		);
	}

	private static function strict_json_file( string $path ) {
		$text = @file_get_contents( $path );
		if ( false === $text || '' === $text || "\n" !== substr( $text, -1 ) ) {
			throw new \RuntimeException( "JSON file is missing or incomplete: {$path}" );
		}
		return StrictJsonParser::decode( substr( $text, 0, -1 ) );
	}

	private static function file_identity( string $path ): array {
		clearstatcache( true, $path );
		$resolved = realpath( $path );
		if ( false === $resolved || ! is_file( $resolved ) || is_link( $path ) ) {
			throw new \RuntimeException( "Trust input is missing, non-regular, or symlinked: {$path}" );
		}
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( "Trust input is unreadable: {$path}" );
		}
		$before = fstat( $handle );
		$context = hash_init( 'sha256' );
		$bytes = hash_update_stream( $context, $handle );
		$sha256 = hash_final( $context );
		$after = fstat( $handle );
		$closed = fclose( $handle );
		clearstatcache( true, $resolved );
		$later = @lstat( $resolved );
		$stable_fields = array( 'dev', 'ino', 'mode', 'size', 'mtime', 'ctime' );
		if ( ! is_array( $before ) || ! is_array( $after ) || ! is_array( $later ) || false === $bytes || ! $closed || $bytes !== $before['size'] || 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
			throw new \RuntimeException( "Could not read a complete trust identity: {$path}" );
		}
		foreach ( $stable_fields as $field ) {
			if ( ! array_key_exists( $field, $before ) || $before[ $field ] !== $after[ $field ] || $before[ $field ] !== $later[ $field ] ) {
				throw new \RuntimeException( "Trust input changed while hashing: {$path}" );
			}
		}
		return array(
			'path' => $resolved,
			'mode' => sprintf( '%06o', $before['mode'] & 0177777 ),
			'bytes' => $bytes,
			'sha256' => $sha256,
		);
	}

	private static function seal_directory( string $directory, array $excluded ): array {
		$rows = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			$relative = substr( $path, strlen( $directory ) + 1 );
			if ( in_array( $relative, $excluded, true ) ) {
				continue;
			}
			if ( $file->isLink() || ! $file->isFile() ) {
				throw new \RuntimeException( "Cannot seal non-regular evidence: {$relative}" );
			}
			$identity = self::file_identity( $path );
			$rows[] = array( 'path' => $relative, 'mode' => $identity['mode'], 'bytes' => $identity['bytes'], 'sha256' => $identity['sha256'] );
		}
		usort( $rows, static fn ( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
		return array(
			'kind' => 'html-api-commoncrawl-evidence-seal',
			'files' => $rows,
			'filesSha256' => hash( 'sha256', self::canonical_json( $rows ) ),
		);
	}

	private static function canonical_json( $value ): string {
		$canonical = self::canonicalize( $value );
		$json = json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new \RuntimeException( 'Could not encode canonical coordinator JSON.' );
		}
		return $json;
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

	private static function existing_file_option( array $options, string $name ): string {
		return self::resolved_file( self::required_string_option( $options, $name ), "--{$name}" );
	}

	private static function existing_directory_option( array $options, string $name ): string {
		$value = self::required_string_option( $options, $name );
		$resolved = realpath( $value );
		if ( false === $resolved || ! is_dir( $resolved ) || is_link( $value ) ) {
			throw new \InvalidArgumentException( "--{$name} must resolve to a non-symlink directory." );
		}
		return $resolved;
	}

	private static function new_path_option( array $options, string $name ): string {
		$value = self::required_string_option( $options, $name );
		if ( file_exists( $value ) || is_link( $value ) ) {
			throw new \InvalidArgumentException( "--{$name} must not already exist." );
		}
		$parent = realpath( dirname( $value ) );
		if ( false === $parent || ! is_dir( $parent ) ) {
			throw new \InvalidArgumentException( "--{$name} parent directory does not exist." );
		}
		return $parent . DIRECTORY_SEPARATOR . basename( $value );
	}

	private static function required_string_option( array $options, string $name ): string {
		if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] || ! is_string( $options[ $name ] ) || '' === $options[ $name ] ) {
			throw new \InvalidArgumentException( "Expected --{$name} with a non-empty value." );
		}
		return $options[ $name ];
	}

	private static function required_positive_option( array $options, string $name ): int {
		if ( ! array_key_exists( $name, $options ) || true === $options[ $name ] ) {
			throw new \InvalidArgumentException( "Expected --{$name} with a positive integer value." );
		}
		return self::positive_option( $options, $name, 0 );
	}

	private static function positive_option( array $options, string $name, int $default, int $minimum = 1 ): int {
		$value = option_int( $options, $name, $default );
		if ( $value < $minimum ) {
			throw new \InvalidArgumentException( "--{$name} must be at least {$minimum}." );
		}
		return $value;
	}

	private static function strict_bool_option( array $options, string $name, bool $default ): bool {
		if ( ! array_key_exists( $name, $options ) ) {
			return $default;
		}
		if ( true === $options[ $name ] ) {
			return true;
		}
		$value = filter_var( $options[ $name ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $value ) {
			throw new \InvalidArgumentException( "--{$name} must be a boolean value." );
		}
		return $value;
	}

	private static function validate_options( array $options ): void {
		$value_options = array(
			'cc-analyzer', 'workspace', 'batch', 'output-dir', 'coordinator-id',
			'batch-timeout-ms', 'process-timeout-ms', 'oracle-timeout-ms', 'chrome-startup-timeout-ms',
			'checks', 'memory-limit', 'max-input-bytes', 'max-tokens', 'max-nodes', 'max-depth', 'max-tree-bytes',
			'lexbor-oracle-bin', 'html5ever-oracle-bin', 'chrome-oracle-script', 'chrome-executable', 'node-bin',
		);
		$flag_options = array( 'retain-all', 'require-utf8' );
		if ( ! is_array( $options['_'] ?? null ) || array() !== $options['_'] ) {
			throw new \InvalidArgumentException( 'Coordinator does not accept positional arguments.' );
		}
		foreach ( $options as $name => $value ) {
			if ( '_' === $name ) {
				continue;
			}
			if ( ! in_array( $name, $value_options, true ) && ! in_array( $name, $flag_options, true ) ) {
				throw new \InvalidArgumentException( "Unknown coordinator option --{$name}." );
			}
			if ( in_array( $name, $value_options, true ) && true === $value ) {
				throw new \InvalidArgumentException( "Coordinator option --{$name} requires a value." );
			}
		}
	}

	private static function resolved_file( ?string $path, string $label ): string {
		if ( null === $path || '' === $path ) {
			throw new \InvalidArgumentException( "{$label} path is required." );
		}
		$resolved = realpath( $path );
		if ( false === $resolved || ! is_file( $resolved ) || is_link( $path ) ) {
			throw new \InvalidArgumentException( "{$label} must resolve to a non-symlink regular file." );
		}
		return $resolved;
	}

	private static function resolved_executable( ?string $path, string $label ): string {
		$resolved = self::resolved_file( $path, $label );
		if ( ! is_executable( $resolved ) ) {
			throw new \InvalidArgumentException( "{$label} is not executable." );
		}
		return $resolved;
	}
}
