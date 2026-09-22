<?php
namespace HtmlApiFuzz;

/** Strict persistent client for the pinned direct-CDP Chrome oracle. */
final class ChromeOracleRenderer {
	public const DEFAULT_STARTUP_TIMEOUT_MS = 35000;
	public const MAX_INPUT_BYTES = 2097152;

	private const MAX_FRAME_BYTES = 25165824;
	private const MAX_STDERR_BYTES = 1048576;
	private const CLEANUP_TIMEOUT_MS = 10000;
	private const CLEANUP_HEADROOM_MS = 5000;

	private string $script;
	private string $chrome_executable;
	private string $node_executable;
	private int $render_timeout_ms;
	private int $startup_timeout_ms;
	private ?array $metadata = null;
	private ?array $verified_identity = null;
	private $process = null;
	private array $pipes = array();
	private string $stdout = '';
	private string $stderr_tail = '';
	private int $stderr_bytes = 0;
	private bool $stderr_overflow = false;
	private ?int $observed_exit_code = null;
	private int $request_id = 0;
	private array $runtime_roots = array();
	private static array $instances = array();
	private static bool $shutdown_registered = false;

	public function __construct( string $script, ?string $chrome_executable, string $node_binary, int $render_timeout_ms, int $startup_timeout_ms ) {
		if ( $render_timeout_ms < 1 || $startup_timeout_ms < 1 ) {
			throw new \InvalidArgumentException( 'Chrome render and startup timeouts must be positive.' );
		}
		$this->script              = $script;
		$this->chrome_executable   = $chrome_executable ?? '';
		$this->node_executable     = $node_binary;
		$this->render_timeout_ms   = $render_timeout_ms;
		$this->startup_timeout_ms  = $startup_timeout_ms;

		self::$instances[] = \WeakReference::create( $this );
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( array( self::class, 'shutdown_all' ) );
			self::$shutdown_registered = true;
		}
	}

	public static function shutdown_all(): void {
		foreach ( self::$instances as $reference ) {
			$instance = $reference->get();
			if ( $instance instanceof self ) {
				$instance->close_silently();
			}
		}
		self::$instances = array();
	}

	public function __destruct() {
		$this->close_silently();
	}

	public function startup_timeout_ms(): int {
		return $this->startup_timeout_ms;
	}

	public function render_timeout_ms(): int {
		return $this->render_timeout_ms;
	}

	public function script(): string {
		return $this->script;
	}

	public function chrome_executable(): string {
		return $this->chrome_executable;
	}

	public function node_executable(): string {
		return $this->node_executable;
	}

	public function recommended_process_timeout_ms( string $checks ): int {
		$render_calls = 'baseline' === $checks ? 1 : 4;
		if ( $this->render_timeout_ms > intdiv( PHP_INT_MAX, $render_calls ) ) {
			throw new \OverflowException( 'Chrome Worker render-call budget exceeds the platform integer range.' );
		}
		$parts = array( $this->startup_timeout_ms, $render_calls * $this->render_timeout_ms, self::CLEANUP_TIMEOUT_MS, self::CLEANUP_HEADROOM_MS );
		$total = 0;
		foreach ( $parts as $part ) {
			if ( $part < 0 || $total > PHP_INT_MAX - $part ) {
				throw new \OverflowException( 'Chrome Worker timeout recommendation exceeds the platform integer range.' );
			}
			$total += $part;
		}
		return $total;
	}

	public function metadata(): array {
		if ( null !== $this->metadata ) {
			return $this->metadata;
		}
		try {
			$this->prepare_paths();
			$this->ensure_service();
			$this->metadata = array(
				'schemaVersion' => 1,
				'kind'          => OracleRenderer::KIND_CHROME_CDP,
				'available'     => true,
				'identity'      => $this->verified_identity,
				'error'         => null,
			);
		} catch ( \Throwable $error ) {
			$cleanup_error = $this->stop_service( false );
			$this->metadata = array(
				'schemaVersion' => 1,
				'kind'          => OracleRenderer::KIND_CHROME_CDP,
				'available'     => false,
				'identity'      => null,
				'error'         => $error->getMessage() . ( null === $cleanup_error ? '' : '; cleanup failed: ' . $cleanup_error ),
			);
		}
		return $this->metadata;
	}

	public function render( string $html, string $mode, array $limits, string $fragment_context ): array {
		$metadata = $this->metadata();
		if ( true !== $metadata['available'] ) {
			return $this->infrastructure_result( 'Chrome CDP oracle is unavailable: ' . (string) $metadata['error'] );
		}
		if ( strlen( $html ) > self::MAX_INPUT_BYTES ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'failureClass' => 'input-byte-limit-exceeded',
				'error'        => 'Chrome oracle input exceeded the exact 2 MiB byte limit.',
				'nodeCount'    => 0,
				'oracle'       => $metadata,
			);
		}
		$max_nodes      = self::positive_limit( $limits, 'maxNodes', 3000, 100000 );
		$max_depth      = self::positive_limit( $limits, 'maxDepth', 512, 1024 );
		$max_tree_bytes = self::positive_limit( $limits, 'maxTreeBytes', 16777216, 16777216 );
		$started_at = hrtime( true );
		try {
			$this->ensure_service();
			$before = $this->local_snapshot();
			$request = array(
					'command'      => 'render',
					'htmlBase64'   => base64_encode( $html ),
					'mode'         => $mode,
					'maxNodes'     => $max_nodes,
					'maxDepth'     => $max_depth,
					'maxTreeBytes' => $max_tree_bytes,
				);
			if ( Generator::MODE_FRAGMENT_BODY === $mode ) {
				$request['context'] = $fragment_context;
			}
			$response = $this->request(
				$request,
				$this->render_timeout_ms
			);
			$after = $this->local_snapshot();
			if ( ! hash_equals( self::canonical_json( $before ), self::canonical_json( $after ) ) ) {
				throw new \RuntimeException( 'Chrome oracle trust inputs changed during rendering.' );
			}
			$result = $this->validate_render_response( $response, $max_nodes, $max_tree_bytes );
			$result['oracle'] = $metadata;
			$result['process'] = array(
				'durationMs' => round( max( 0, hrtime( true ) - $started_at ) / 1000000, 3 ),
				'persistent' => true,
			);
			return $result;
		} catch ( \Throwable $error ) {
			$cleanup_error = $this->stop_service( false );
			return $this->infrastructure_result(
				$error->getMessage() . ( null === $cleanup_error ? '' : '; cleanup failed: ' . $cleanup_error ),
				round( max( 0, hrtime( true ) - $started_at ) / 1000000, 3 )
			);
		}
	}

	public function close(): void {
		$error = $this->stop_service( true );
		if ( null !== $error ) {
			throw new \RuntimeException( $error );
		}
	}

	private function close_silently(): void {
		try {
			$this->close();
		} catch ( \Throwable $ignored ) {
			// Explicit owners surface cleanup failures before completing work.
		}
	}

	private function ensure_service(): void {
		if ( is_resource( $this->process ) ) {
			$status = proc_get_status( $this->process );
			if ( $status['running'] ) {
				return;
			}
			$cleanup_error = $this->stop_service( false );
			if ( null !== $cleanup_error ) {
				throw new \RuntimeException( 'Dead Chrome service cleanup failed: ' . $cleanup_error );
			}
		}

		$before = $this->local_snapshot();
		$command = array( $this->node_executable, $this->script, '--serve', '--engine', 'chrome', '--chrome-executable', $this->chrome_executable );
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$this->process = @proc_open( $command, $spec, $this->pipes, repo_root(), null, array( 'bypass_shell' => true ) );
		if ( ! is_resource( $this->process ) ) {
			$this->process = null;
			$this->pipes = array();
			throw new \RuntimeException( 'Could not start the Chrome oracle service.' );
		}
		foreach ( $this->pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}
		stream_set_write_buffer( $this->pipes[0], 0 );
		$this->stdout = '';
		$this->stderr_tail = '';
		$this->stderr_bytes = 0;
		$this->stderr_overflow = false;
		$this->observed_exit_code = null;

		$response = $this->request( array( 'command' => 'version' ), $this->startup_timeout_ms );
		if ( ! self::exact_keys( $response, array( 'id', 'status', 'oracle' ) ) || 'ok' !== $response['status'] ) {
			throw new \RuntimeException( 'Chrome service returned an invalid version response.' );
		}
		$identity = $this->validate_oracle( $response['oracle'], true );
		if ( null !== $this->verified_identity && ! hash_equals( self::canonical_json( $this->verified_identity ), self::canonical_json( $identity ) ) ) {
			throw new \RuntimeException( 'Restarted Chrome service identity differs from its verified identity.' );
		}
		$this->verified_identity = $identity;
		$after = $this->local_snapshot();
		if ( ! hash_equals( self::canonical_json( $before ), self::canonical_json( $after ) ) ) {
			throw new \RuntimeException( 'Chrome oracle trust inputs changed during startup.' );
		}
	}

	private function request( array $request, int $timeout_ms, bool $allow_exit_after_response = false ): array {
		if ( ! is_resource( $this->process ) || 3 !== count( $this->pipes ) ) {
			throw new \RuntimeException( 'Chrome oracle service is not running.' );
		}
		$this->drain_available();
		if ( '' !== $this->stdout ) {
			throw new \RuntimeException( 'Chrome oracle emitted an unsolicited response frame.' );
		}
		if ( $this->stderr_overflow ) {
			throw new \RuntimeException( 'Chrome oracle stderr exceeded 1 MiB.' );
		}

		$request['id'] = ++$this->request_id;
		$encoded = json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
		if ( strlen( $encoded ) > 4194304 ) {
			throw new \RuntimeException( 'Chrome request frame exceeded 4 MiB.' );
		}
		$offset = 0;
		$deadline = microtime( true ) + ( $timeout_ms / 1000 );
		while ( true ) {
			if ( microtime( true ) >= $deadline ) {
				throw new \RuntimeException( 'Chrome oracle request timed out.' );
			}
			$read = array( $this->pipes[1], $this->pipes[2] );
			$write = $offset < strlen( $encoded ) ? array( $this->pipes[0] ) : array();
			$except = array();
			$remaining_us = max( 1, min( 20000, (int) floor( ( $deadline - microtime( true ) ) * 1000000 ) ) );
			@stream_select( $read, $write, $except, 0, $remaining_us );
			if ( ! empty( $write ) ) {
				$written = @fwrite( $this->pipes[0], substr( $encoded, $offset, 65536 ) );
				if ( false === $written ) {
					throw new \RuntimeException( 'Could not write the Chrome oracle request.' );
				}
				$offset += $written;
			}
			$this->drain_available();
			if ( $this->stderr_overflow ) {
				throw new \RuntimeException( 'Chrome oracle stderr exceeded 1 MiB.' );
			}
			$newline = strpos( $this->stdout, "\n" );
			if ( false !== $newline ) {
				$line = substr( $this->stdout, 0, $newline );
				$trailing = substr( $this->stdout, $newline + 1 );
				$this->stdout = '';
				if ( '' !== $trailing ) {
					throw new \RuntimeException( 'Chrome oracle emitted trailing response bytes.' );
				}
				$decoded = StrictJsonParser::decode( $line );
				if ( ! is_array( $decoded ) || ( $decoded['id'] ?? null ) !== $request['id'] ) {
					throw new \RuntimeException( 'Chrome oracle response id or schema is invalid.' );
				}
				$status = proc_get_status( $this->process );
				$this->remember_exit_code( $status );
				if ( ! $allow_exit_after_response && ! $status['running'] ) {
					throw new \RuntimeException( 'Chrome oracle service exited while returning a response.' );
				}
				return $decoded;
			}
			$status = proc_get_status( $this->process );
			$this->remember_exit_code( $status );
			if ( ! $status['running'] ) {
				throw new \RuntimeException( 'Chrome oracle service exited before returning a response.' );
			}
		}
	}

	private function drain_available(): void {
		if ( ! isset( $this->pipes[1], $this->pipes[2] ) ) {
			return;
		}
		while ( true ) {
			$chunk = @fread( $this->pipes[1], 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->stdout .= $chunk;
			if ( strlen( $this->stdout ) > self::MAX_FRAME_BYTES ) {
				throw new \RuntimeException( 'Chrome oracle stdout frame exceeded 24 MiB.' );
			}
		}
		while ( true ) {
			$chunk = @fread( $this->pipes[2], 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->stderr_bytes += strlen( $chunk );
			$this->stderr_tail = substr( $this->stderr_tail . $chunk, -self::MAX_STDERR_BYTES );
			if ( $this->stderr_bytes > self::MAX_STDERR_BYTES ) {
				$this->stderr_overflow = true;
			}
		}
	}

	private function validate_render_response( array $response, int $max_nodes, int $max_tree_bytes ): array {
		$status = $response['status'] ?? null;
		if ( ! is_string( $status ) || ! is_array( $response['oracle'] ?? null ) ) {
			throw new \RuntimeException( 'Chrome result is missing its status or oracle identity.' );
		}
		$identity = $this->validate_oracle( $response['oracle'], 'ok' === $status || 'unsupported' === $status || 'limit' === $status );
		if ( ! hash_equals( self::canonical_json( $this->verified_identity ), self::canonical_json( $identity ) ) ) {
			throw new \RuntimeException( 'Chrome render identity differs from its verified identity.' );
		}
		if ( 'ok' === $status ) {
			if ( ! self::exact_keys( $response, array( 'id', 'status', 'oracle', 'treeBase64', 'treeBytes', 'treeSha256', 'nodeCount' ) ) ) {
				throw new \RuntimeException( 'Chrome ok result has an invalid exact schema.' );
			}
			$tree = is_string( $response['treeBase64'] ) ? base64_decode( $response['treeBase64'], true ) : false;
			if (
				false === $tree ||
				base64_encode( $tree ) !== $response['treeBase64'] ||
				! is_int( $response['treeBytes'] ) || strlen( $tree ) !== $response['treeBytes'] || strlen( $tree ) > $max_tree_bytes ||
				! is_string( $response['treeSha256'] ) || ! hash_equals( hash( 'sha256', $tree ), $response['treeSha256'] ) ||
				! is_int( $response['nodeCount'] ) || $response['nodeCount'] < 0 || $response['nodeCount'] > $max_nodes ||
				1 !== preg_match( '//u', $tree )
			) {
				throw new \RuntimeException( 'Chrome canonical tree bytes are invalid.' );
			}
			return array( 'status' => TreeRenderer::STATUS_OK, 'tree' => $tree, 'nodeCount' => $response['nodeCount'] );
		}
		if ( 'unsupported' === $status ) {
			if (
				! self::exact_keys( $response, array( 'id', 'status', 'failureClass', 'unsupported', 'oracle' ) ) ||
				'invalid-utf8' !== $response['failureClass'] ||
				! is_array( $response['unsupported'] ) ||
				! self::exact_keys( $response['unsupported'], array( 'reason' ) ) ||
				'invalid-utf8' !== $response['unsupported']['reason']
			) {
				throw new \RuntimeException( 'Chrome unsupported result has an invalid exact schema.' );
			}
			return array(
				'status'       => TreeRenderer::STATUS_UNSUPPORTED,
				'failureClass' => 'invalid-utf8',
				'unsupported'  => array( 'message' => 'Chrome rejected invalid UTF-8 input.' ),
				'nodeCount'    => 0,
			);
		}
		if ( 'limit' === $status || ( 'error' === $status && array_key_exists( 'nodeCount', $response ) ) ) {
			if (
				! self::exact_keys( $response, array( 'id', 'status', 'failureClass', 'error', 'nodeCount', 'treeBytes', 'oracle' ) ) ||
				! is_string( $response['failureClass'] ) ||
				! is_string( $response['error'] ) ||
				! is_int( $response['nodeCount'] ) || $response['nodeCount'] < 0 || $response['nodeCount'] > $max_nodes + 1 ||
				! is_int( $response['treeBytes'] ) || $response['treeBytes'] < 0 || $response['treeBytes'] > $max_tree_bytes
			) {
				throw new \RuntimeException( 'Chrome non-success renderer result has an invalid exact schema.' );
			}
			$limits = array( 'node-limit-exceeded', 'depth-limit-exceeded', 'tree-byte-limit-exceeded' );
			if ( 'limit' === $status && ! in_array( $response['failureClass'], $limits, true ) ) {
				throw new \RuntimeException( 'Chrome returned an untrusted resource-limit class.' );
			}
			if ( 'error' === $status && 'oracle-renderer-error' !== $response['failureClass'] ) {
				throw new \RuntimeException( 'Chrome returned an untrusted renderer error class.' );
			}
			$result = array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'failureClass' => $response['failureClass'],
				'error'        => $response['error'],
				'nodeCount'    => $response['nodeCount'],
			);
			if ( 'error' === $status ) {
				$result['infrastructure'] = true;
			}
			return $result;
		}
		if (
			'error' !== $status ||
			! self::exact_keys( $response, array( 'id', 'status', 'failureClass', 'error', 'oracle' ) ) ||
			! is_string( $response['failureClass'] ) ||
			! in_array( $response['failureClass'], array( 'oracle-infrastructure-failure', 'oracle-infrastructure-timeout', 'oracle-evaluation-timeout', 'oracle-renderer-error', 'protocol-error' ), true ) ||
			! is_string( $response['error'] )
		) {
			throw new \RuntimeException( 'Chrome infrastructure result has an invalid exact schema.' );
		}
		return array(
			'status'         => TreeRenderer::STATUS_ERROR,
			'failureClass'   => 'oracle-renderer-error',
			'error'          => $response['error'],
			'infrastructure' => true,
		);
	}

	private function validate_oracle( $oracle, bool $require_available, bool $validate_transport = true ): array {
		if ( ! is_array( $oracle ) || ! self::exact_keys( $oracle, array( 'kind', 'engine', 'available', 'identity', 'transport' ) ) ) {
			throw new \RuntimeException( 'Chrome oracle metadata envelope has an invalid exact schema.' );
		}
		if (
			OracleRenderer::KIND_CHROME_CDP !== $oracle['kind'] ||
			'chrome' !== $oracle['engine'] ||
			! is_bool( $oracle['available'] ) ||
			( $require_available && true !== $oracle['available'] ) ||
			! is_array( $oracle['identity'] ) ||
			! is_array( $oracle['transport'] )
		) {
			throw new \RuntimeException( 'Chrome oracle metadata envelope values are invalid.' );
		}
		$local = $this->local_snapshot();
		$identity = $oracle['identity'];
		$identity_keys = array(
			'schemaVersion', 'kind', 'platform', 'pinnedChromeVersion', 'chromeArchiveSha256',
			'expectedChromeExecutableSha256', 'chromeExecutableSha256', 'oracleScriptSha256',
			'fragmentContextsSha256', 'fragmentContexts', 'nodeExecutableSha256', 'nodeVersion',
			'chromeVersion', 'cdpProtocolVersion',
		);
		if ( ! self::exact_keys( $identity, $identity_keys ) ) {
			throw new \RuntimeException( 'Chrome durable identity has an invalid exact schema.' );
		}
		$expected = array(
			'schemaVersion'                    => 1,
			'kind'                             => OracleRenderer::KIND_CHROME_CDP,
			'platform'                         => $local['platform'],
			'pinnedChromeVersion'              => $local['pinnedChromeVersion'],
			'chromeArchiveSha256'              => $local['chromeArchiveSha256'],
			'expectedChromeExecutableSha256'   => $local['expectedChromeExecutableSha256'],
			'chromeExecutableSha256'           => $local['chromeExecutableSha256'],
			'oracleScriptSha256'               => $local['oracleScriptSha256'],
			'fragmentContextsSha256'           => $local['fragmentContextsSha256'],
			'fragmentContexts'                 => $local['fragmentContexts'],
			'nodeExecutableSha256'             => $local['nodeExecutableSha256'],
			'nodeVersion'                      => $identity['nodeVersion'] ?? null,
			'chromeVersion'                    => $local['pinnedChromeVersion'],
			'cdpProtocolVersion'               => $identity['cdpProtocolVersion'] ?? null,
		);
		if (
			! is_string( $expected['nodeVersion'] ) || 1 !== preg_match( '/^v[0-9]+(?:\.[0-9]+){2}(?:[-+][0-9A-Za-z.-]+)?$/D', $expected['nodeVersion'] ) ||
			! is_string( $expected['cdpProtocolVersion'] ) || '' === $expected['cdpProtocolVersion'] ||
			! hash_equals( self::canonical_json( $expected ), self::canonical_json( $identity ) )
		) {
			throw new \RuntimeException( 'Chrome durable identity does not match local trust anchors.' );
		}
		if ( $validate_transport ) {
			$this->validate_transport( $oracle['transport'] );
		}
		return $expected;
	}

	private function validate_transport( array $transport ): void {
		$keys = array(
			'replayExcluded', 'ownerPid', 'chromeExecutablePath', 'oracleScriptPath', 'nodeExecutablePath',
			'runtimeRoot', 'profilePath', 'debugEndpoint', 'supervisorPid', 'browserPid', 'browserInstance',
		);
		if ( ! self::exact_keys( $transport, $keys ) ) {
			throw new \RuntimeException( 'Chrome transport metadata has an invalid exact schema.' );
		}
		$status = is_resource( $this->process ) ? proc_get_status( $this->process ) : array();
		$owner_pid = (int) ( $status['pid'] ?? 0 );
		$base = realpath( sys_get_temp_dir() );
		$root = $transport['runtimeRoot'] ?? null;
		$resolved_root = is_string( $root ) ? realpath( $root ) : false;
		if (
			true !== $transport['replayExcluded'] ||
			$owner_pid < 2 || $owner_pid !== $transport['ownerPid'] ||
			$this->chrome_executable !== ( realpath( $transport['chromeExecutablePath'] ?? '' ) ?: null ) ||
			$this->script !== ( realpath( $transport['oracleScriptPath'] ?? '' ) ?: null ) ||
			$this->node_executable !== ( realpath( $transport['nodeExecutablePath'] ?? '' ) ?: null ) ||
			! is_string( $base ) || ! is_string( $root ) || ! is_string( $resolved_root ) || 1 !== preg_match( '#^' . preg_quote( $base, '#' ) . '/html-api-fuzz-chrome-' . $owner_pid . '-[0-9a-f]{32}$#D', $resolved_root ) ||
			$root . '/profile' !== ( $transport['profilePath'] ?? null ) ||
			! is_string( $transport['debugEndpoint'] ) || 1 !== preg_match( '#^ws://127\.0\.0\.1:[0-9]+/#', $transport['debugEndpoint'] ) ||
			! is_int( $transport['supervisorPid'] ) || $transport['supervisorPid'] < 2 ||
			! is_int( $transport['browserPid'] ) || $transport['browserPid'] < 2 ||
			! is_int( $transport['browserInstance'] ) || $transport['browserInstance'] < 1
		) {
			throw new \RuntimeException( 'Chrome transport metadata values are invalid.' );
		}
		$stat = @lstat( $root );
		if ( false === $stat || ( $stat['mode'] & 0170000 ) !== 0040000 || ( $stat['mode'] & 0777 ) !== 0700 || ( function_exists( 'posix_geteuid' ) && $stat['uid'] !== posix_geteuid() ) ) {
			throw new \RuntimeException( 'Chrome runtime root ownership is invalid.' );
		}
		foreach ( $this->runtime_roots as $previous ) {
			if ( $previous !== $root && false !== @lstat( $previous ) ) {
				throw new \RuntimeException( 'A replaced Chrome runtime root still exists.' );
			}
		}
		$this->runtime_roots[ $root ] = $root;
	}

	private function prepare_paths(): void {
		$this->script = self::resolve_regular_file( $this->script, false );
		$this->node_executable = self::resolve_node_executable( $this->node_executable );
		$local = $this->local_snapshot( true );
		if ( '' === $this->chrome_executable ) {
			$trust_dir = repo_root() . '/tools/html-api-fuzz/oracles/chrome';
			$install_root = getenv( 'HTML_API_FUZZ_CHROME_INSTALL_ROOT' );
			if ( ! is_string( $install_root ) || '' === $install_root ) {
				$install_root = $trust_dir . '/.chrome-for-testing';
			}
			$archive_dir = str_starts_with( $local['platform'], 'mac-' ) ? 'chrome-' . $local['platform'] : 'chrome-linux64';
			$relative = str_starts_with( $local['platform'], 'mac-' )
				? 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
				: 'chrome';
			$this->chrome_executable = $install_root . '/' . $local['pinnedChromeVersion'] . '/' . $local['platform'] . '/' . $archive_dir . '/' . $relative;
		}
		$this->chrome_executable = self::resolve_regular_file( $this->chrome_executable, true );
		$this->local_snapshot();
	}

	private function local_snapshot( bool $without_chrome = false ): array {
		$trust_dir = repo_root() . '/tools/html-api-fuzz/oracles/chrome';
		$version_raw = self::read_stable_file( $trust_dir . '/VERSION', 128 );
		if ( 1 !== preg_match( '/^[0-9]+(?:\.[0-9]+){3}\n$/D', $version_raw ) ) {
			throw new \RuntimeException( 'Chrome VERSION is not canonical.' );
		}
		$version = substr( $version_raw, 0, -1 );
		$platform = self::platform();
		$archive_key = 'chrome-' . $version . '-' . $platform . '.zip';
		$archive_hash = self::manifest_digest( $trust_dir . '/SHA256SUMS', $archive_key );
		$executable_hash = self::manifest_digest( $trust_dir . '/EXECUTABLE_SHA256SUMS', $platform . '.executable' );
		$contexts_raw = self::read_stable_file( repo_root() . '/tools/html-api-fuzz/oracles/fragment-contexts.json', 65536 );
		$contexts = StrictJsonParser::decode( $contexts_raw );
		if ( ! is_array( $contexts ) || $contexts !== Generator::fragment_contexts() ) {
			throw new \RuntimeException( 'Chrome fragment contexts differ from the generator contexts.' );
		}
		$snapshot = array(
			'platform'                         => $platform,
			'pinnedChromeVersion'              => $version,
			'chromeArchiveSha256'              => $archive_hash,
			'expectedChromeExecutableSha256'   => $executable_hash,
			'oracleScriptSha256'               => self::hash_stable_file( $this->script, false ),
			'fragmentContextsSha256'           => hash( 'sha256', $contexts_raw ),
			'fragmentContexts'                 => $contexts,
			'nodeExecutableSha256'             => self::hash_stable_file( $this->node_executable, true ),
			'rawTrustSha256'                   => array(
				'VERSION'                  => hash( 'sha256', $version_raw ),
				'SHA256SUMS'               => hash( 'sha256', self::read_stable_file( $trust_dir . '/SHA256SUMS', 65536 ) ),
				'EXECUTABLE_SHA256SUMS'     => hash( 'sha256', self::read_stable_file( $trust_dir . '/EXECUTABLE_SHA256SUMS', 65536 ) ),
			),
		);
		if ( ! $without_chrome ) {
			$actual = self::hash_stable_file( $this->chrome_executable, true );
			if ( ! hash_equals( $executable_hash, $actual ) ) {
				throw new \RuntimeException( 'Chrome executable does not match its checked-in SHA-256.' );
			}
			$snapshot['chromeExecutableSha256'] = $actual;
		}
		return $snapshot;
	}

	private function stop_service( bool $require_acknowledgement ): ?string {
		$deadline = microtime( true ) + ( self::CLEANUP_TIMEOUT_MS / 1000 );
		if ( ! is_resource( $this->process ) ) {
			$this->process = null;
			$this->pipes = array();
			return $this->wait_for_runtime_roots_absent( $deadline );
		}
		$errors = array();
		$status = proc_get_status( $this->process );
		$this->remember_exit_code( $status );
		$acknowledged = false;
		if ( $status['running'] ) {
			try {
				$remaining_ms = max( 1, (int) floor( ( $deadline - microtime( true ) ) * 1000 ) );
				$response = $this->request( array( 'command' => 'shutdown' ), min( 2000, $remaining_ms ), true );
				if (
					! self::exact_keys( $response, array( 'id', 'status', 'oracle', 'shutdown' ) ) ||
					'ok' !== $response['status'] || true !== $response['shutdown']
				) {
					throw new \RuntimeException( 'Chrome shutdown acknowledgement is invalid.' );
				}
				$this->validate_oracle( $response['oracle'], false, false );
				$acknowledged = true;
			} catch ( \Throwable $error ) {
				if ( $require_acknowledgement ) {
					$errors[] = $error->getMessage();
				}
			}
		}
		if ( $require_acknowledgement && ! $acknowledged && empty( $errors ) ) {
			$errors[] = 'Chrome oracle service exited before its shutdown acknowledgement.';
		}
		if ( isset( $this->pipes[0] ) && is_resource( $this->pipes[0] ) ) {
			fclose( $this->pipes[0] );
		}
		$status = $this->wait_for_exit( min( $deadline, microtime( true ) + 5.0 ) );
		if ( $status['running'] ) {
			@proc_terminate( $this->process, SIGTERM );
			$status = $this->wait_for_exit( min( $deadline, microtime( true ) + 2.0 ) );
		}
		if ( $status['running'] ) {
			@proc_terminate( $this->process, SIGKILL );
			$status = $this->wait_for_exit( $deadline );
		}
		if ( $status['running'] ) {
			$errors[] = 'Exact Chrome oracle child survived SIGKILL deadline.';
		}
		try {
			$this->drain_available();
		} catch ( \Throwable $error ) {
			$errors[] = $error->getMessage();
		}
		foreach ( array( 1, 2 ) as $descriptor ) {
			if ( isset( $this->pipes[ $descriptor ] ) && is_resource( $this->pipes[ $descriptor ] ) ) {
				fclose( $this->pipes[ $descriptor ] );
			}
		}
		$close_code = @proc_close( $this->process );
		$this->remember_exit_code( $status );
		$observed_code = null !== $this->observed_exit_code ? $this->observed_exit_code : $close_code;
		if ( $acknowledged && 0 !== $observed_code ) {
			$errors[] = 'Acknowledged Chrome oracle service exited nonzero.';
		}
		if ( '' !== $this->stdout ) {
			$errors[] = 'Chrome oracle emitted bytes after its shutdown acknowledgement.';
		}
		$this->process = null;
		$this->pipes = array();
		$this->stdout = '';
		$this->observed_exit_code = null;
		if ( $this->stderr_overflow ) {
			$errors[] = 'Chrome oracle stderr exceeded 1 MiB.';
		}
		$root_error = $this->wait_for_runtime_roots_absent( $deadline );
		if ( null !== $root_error ) {
			$errors[] = $root_error;
		}
		return empty( $errors ) ? null : implode( '; ', array_unique( $errors ) );
	}

	private function wait_for_exit( float $deadline ): array {
		$status = proc_get_status( $this->process );
		$this->remember_exit_code( $status );
		while ( $status['running'] && microtime( true ) < $deadline ) {
			try {
				$this->drain_available();
			} catch ( \Throwable $ignored ) {
				$this->stderr_overflow = true;
			}
			$remaining_us = (int) floor( ( $deadline - microtime( true ) ) * 1000000 );
			if ( $remaining_us > 0 ) {
				usleep( min( 10000, $remaining_us ) );
			}
			$status = proc_get_status( $this->process );
			$this->remember_exit_code( $status );
		}
		return $status;
	}

	private function remember_exit_code( array $status ): void {
		if ( ! $status['running'] && is_int( $status['exitcode'] ?? null ) && $status['exitcode'] >= 0 ) {
			$this->observed_exit_code = $status['exitcode'];
		}
	}

	private function wait_for_runtime_roots_absent( float $deadline ): ?string {
		foreach ( $this->runtime_roots as $root ) {
			while ( true ) {
				clearstatcache( true, $root );
				if ( false === @lstat( $root ) ) {
					continue 2;
				}
				$remaining_us = (int) floor( ( $deadline - microtime( true ) ) * 1000000 );
				if ( $remaining_us <= 0 ) {
					break;
				}
				usleep( min( 10000, $remaining_us ) );
			}
			return 'Authenticated Chrome runtime root survived cleanup: ' . $root;
		}
		$this->runtime_roots = array();
		return null;
	}

	private function infrastructure_result( string $message, ?float $duration_ms = null ): array {
		$result = array(
			'status'         => TreeRenderer::STATUS_ERROR,
			'failureClass'   => 'oracle-renderer-error',
			'error'          => $message,
			'infrastructure' => true,
			'oracle'         => $this->metadata ?? array(
				'schemaVersion' => 1,
				'kind'          => OracleRenderer::KIND_CHROME_CDP,
				'available'     => false,
				'identity'      => null,
				'error'         => $message,
			),
		);
		if ( null !== $duration_ms ) {
			$result['process'] = array(
				'durationMs' => $duration_ms,
				'persistent' => true,
				'stderrTail' => $this->stderr_tail,
			);
		}
		return $result;
	}

	private static function platform(): string {
		$machine = strtolower( php_uname( 'm' ) );
		if ( 'Darwin' === PHP_OS_FAMILY && in_array( $machine, array( 'arm64', 'aarch64' ), true ) ) {
			return 'mac-arm64';
		}
		if ( 'Darwin' === PHP_OS_FAMILY && in_array( $machine, array( 'x86_64', 'amd64' ), true ) ) {
			return 'mac-x64';
		}
		if ( 'Linux' === PHP_OS_FAMILY && in_array( $machine, array( 'x86_64', 'amd64' ), true ) ) {
			return 'linux64';
		}
		throw new \RuntimeException( 'Chrome is unsupported on ' . PHP_OS_FAMILY . '/' . $machine . '.' );
	}

	private static function manifest_digest( string $path, string $key ): string {
		$matches = array();
		foreach ( preg_split( '/\r?\n/', self::read_stable_file( $path, 65536 ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$fields = preg_split( '/\s+/', $line );
			if ( 2 === count( $fields ) && $key === $fields[1] ) {
				$matches[] = $fields[0];
			}
		}
		if ( 1 !== count( $matches ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $matches[0] ) ) {
			throw new \RuntimeException( 'Chrome checksum manifest is missing one exact ' . $key . ' record.' );
		}
		return $matches[0];
	}

	private static function resolve_executable( string $command ): string {
		if ( str_contains( $command, DIRECTORY_SEPARATOR ) ) {
			$path = str_starts_with( $command, DIRECTORY_SEPARATOR ) ? $command : repo_root() . DIRECTORY_SEPARATOR . $command;
			return self::resolve_regular_file( $path, true );
		}
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $directory ) {
			if ( '' === $directory ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $command;
			if ( is_file( $path ) && is_executable( $path ) ) {
				return self::resolve_regular_file( $path, true );
			}
		}
		throw new \RuntimeException( 'Could not resolve executable command: ' . $command );
	}

	private static function resolve_node_executable( string $command ): string {
		$launch = str_contains( $command, DIRECTORY_SEPARATOR )
			? self::resolve_executable( $command )
			: $command;
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( array( $launch, '-p', 'process.execPath' ), $spec, $pipes, repo_root(), null, array( 'bypass_shell' => true ) );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not probe the selected Node command.' );
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$stdout = '';
		$stderr = '';
		$deadline = microtime( true ) + 5.0;
		$status = proc_get_status( $process );
		while ( $status['running'] && microtime( true ) < $deadline ) {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			if ( strlen( $stdout ) > 4096 || strlen( $stderr ) > 4096 ) {
				break;
			}
			usleep( 10000 );
			$status = proc_get_status( $process );
		}
		if ( $status['running'] ) {
			@proc_terminate( $process, SIGTERM );
			usleep( 100000 );
			$status = proc_get_status( $process );
			if ( $status['running'] ) {
				@proc_terminate( $process, SIGKILL );
			}
		}
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		$path = rtrim( $stdout, "\r\n" );
		if ( 0 !== $code || '' === $path || str_contains( $path, "\n" ) || strlen( $path ) > 4096 || '' !== trim( $stderr ) ) {
			throw new \RuntimeException( 'Selected Node command did not return one quiet process.execPath.' );
		}
		return self::resolve_regular_file( $path, true );
	}

	private static function resolve_regular_file( string $path, bool $executable ): string {
		clearstatcache( true, $path );
		$resolved = realpath( $path );
		$stat = false === $resolved ? false : @lstat( $resolved );
		if ( false === $resolved || false === $stat || ( $stat['mode'] & 0170000 ) !== 0100000 || ( $executable && ! is_executable( $resolved ) ) ) {
			throw new \RuntimeException( 'Required Chrome identity path is not a trusted regular file: ' . $path );
		}
		return $resolved;
	}

	private static function read_stable_file( string $path, int $maximum ): string {
		$resolved = self::resolve_regular_file( $path, false );
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open Chrome identity file.' );
		}
		$before = fstat( $handle );
		$contents = '';
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, min( 65536, $maximum + 1 - strlen( $contents ) ) );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Could not read Chrome identity file.' );
				}
				$contents .= $chunk;
				if ( strlen( $contents ) > $maximum ) {
					throw new \RuntimeException( 'Chrome identity file exceeded its byte limit.' );
				}
			}
			$after = fstat( $handle );
		} finally {
			fclose( $handle );
		}
		clearstatcache( true, $resolved );
		$path_after = @lstat( $resolved );
		self::assert_same_stat( $before, $after, $path_after, 'read' );
		return $contents;
	}

	private static function hash_stable_file( string $path, bool $executable ): string {
		$resolved = self::resolve_regular_file( $path, $executable );
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open Chrome identity file for hashing.' );
		}
		$before = fstat( $handle );
		$hash = hash_init( 'sha256' );
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 1048576 );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Could not hash Chrome identity file.' );
				}
				if ( '' !== $chunk ) {
					hash_update( $hash, $chunk );
				}
			}
			$after = fstat( $handle );
		} finally {
			fclose( $handle );
		}
		clearstatcache( true, $resolved );
		$path_after = @lstat( $resolved );
		self::assert_same_stat( $before, $after, $path_after, 'hash' );
		return hash_final( $hash );
	}

	private static function assert_same_stat( $before, $after, $path_after, string $operation ): void {
		foreach ( array( 'dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime' ) as $field ) {
			if (
				! is_array( $before ) || ! is_array( $after ) || ! is_array( $path_after ) ||
				( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ||
				( $after[ $field ] ?? null ) !== ( $path_after[ $field ] ?? null )
			) {
				throw new \RuntimeException( 'Chrome identity file changed during ' . $operation . '.' );
			}
		}
	}

	private static function positive_limit( array $limits, string $key, int $default, int $maximum ): int {
		$value = $limits[ $key ] ?? $default;
		if ( ! is_int( $value ) || $value < 1 || $value > $maximum ) {
			throw new \InvalidArgumentException( $key . ' must be between 1 and ' . $maximum . ' for Chrome.' );
		}
		return $value;
	}

	private static function canonical_json( $value ): string {
		return json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function exact_keys( array $value, array $keys ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $keys, SORT_STRING );
		return $actual === $keys;
	}
}
