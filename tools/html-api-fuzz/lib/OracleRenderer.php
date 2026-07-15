<?php
namespace HtmlApiFuzz;

class OracleRenderer {
	public const KIND_LEXBOR_SOURCE     = 'lexbor-source';
	public const KIND_HTML5EVER_SOURCE  = 'html5ever-source';
	public const KIND_CHROME_CDP        = 'chrome-cdp';

	private string $kind;
	private ?string $lexbor_oracle_bin;
	private ?string $html5ever_oracle_bin;
	private ?string $chrome_oracle_script;
	private ?string $chrome_executable;
	private ?string $chrome_socket;
	private string $node_bin;
	private int $timeout_ms;
	private ?array $metadata = null;
	private ?string $run_service_key = null;
	private static array $metadata_cache = array();
	private static array $chrome_servers = array();
	private static array $chrome_run_services = array();
	private static int $chrome_request_id = 0;
	private static bool $chrome_shutdown_registered = false;

	private function __construct(
		string $kind,
		?string $lexbor_oracle_bin = null,
		?string $html5ever_oracle_bin = null,
		?string $chrome_oracle_script = null,
		?string $chrome_executable = null,
		?string $chrome_socket = null,
		string $node_bin = 'node',
		int $timeout_ms = 2500
	) {
		$this->kind                    = $kind;
		$this->lexbor_oracle_bin       = $lexbor_oracle_bin;
		$this->html5ever_oracle_bin    = $html5ever_oracle_bin;
		$this->chrome_oracle_script    = $chrome_oracle_script;
		$this->chrome_executable       = $chrome_executable;
		$this->chrome_socket           = $chrome_socket;
		$this->node_bin                = $node_bin;
		$this->timeout_ms              = $timeout_ms;
	}

	public static function from_options( array $options ): self {
		$kind = option_string( $options, 'dom-oracle', self::KIND_LEXBOR_SOURCE );
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			throw new \InvalidArgumentException( 'Expected --dom-oracle to be one of: ' . implode( ', ', self::kinds() ) . '.' );
		}
		$timeout_ms = option_int( $options, 'oracle-timeout-ms', 2500 );
		if ( $timeout_ms < 1 ) {
			throw new \InvalidArgumentException( 'Expected --oracle-timeout-ms to be at least 1.' );
		}

		$lexbor_oracle_bin = option_string( $options, 'lexbor-oracle-bin', getenv( 'HTML_API_FUZZ_LEXBOR_ORACLE' ) ?: null );
		if ( self::KIND_LEXBOR_SOURCE === $kind && ( null === $lexbor_oracle_bin || '' === $lexbor_oracle_bin ) ) {
			$lexbor_oracle_bin = repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle';
		}

		$html5ever_oracle_bin = option_string( $options, 'html5ever-oracle-bin', getenv( 'HTML_API_FUZZ_HTML5EVER_ORACLE' ) ?: null );
		if ( self::KIND_HTML5EVER_SOURCE === $kind && ( null === $html5ever_oracle_bin || '' === $html5ever_oracle_bin ) ) {
			$html5ever_oracle_bin = repo_root() . '/tools/html-api-fuzz/oracles/html5ever/build/html5ever-tree-oracle';
		}

		$chrome_oracle_script = option_string( $options, 'chrome-oracle-script', getenv( 'HTML_API_FUZZ_CHROME_ORACLE' ) ?: null );
		if ( self::KIND_CHROME_CDP === $kind && ( null === $chrome_oracle_script || '' === $chrome_oracle_script ) ) {
			$chrome_oracle_script = repo_root() . '/tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js';
		}

		return new self(
			$kind,
			$lexbor_oracle_bin,
			$html5ever_oracle_bin,
			$chrome_oracle_script,
			option_string( $options, 'chrome-executable', getenv( 'HTML_API_FUZZ_CHROME_EXECUTABLE' ) ?: null ),
			option_string( $options, 'chrome-socket', getenv( 'HTML_API_FUZZ_CHROME_SOCKET' ) ?: null ),
			option_string( $options, 'node-bin', getenv( 'HTML_API_FUZZ_NODE_BIN' ) ?: 'node' ),
			$timeout_ms
		);
	}

	public static function kinds(): array {
		return array(
			self::KIND_LEXBOR_SOURCE,
			self::KIND_HTML5EVER_SOURCE,
			self::KIND_CHROME_CDP,
		);
	}

	public function kind(): string {
		return $this->kind;
	}

	public function assert_replay_compatible( array $expected ): void {
		$current = $this->metadata();
		foreach ( array( 'kind', 'lexborCommit', 'html5everVersion', 'html5everChecksum', 'pinnedChromeVersion' ) as $key ) {
			if ( ! is_string( $expected[ $key ] ?? null ) || '' === $expected[ $key ] ) {
				continue;
			}
			if ( ( $current[ $key ] ?? null ) !== $expected[ $key ] ) {
				throw new \RuntimeException( "Replay oracle mismatch for {$key}: expected {$expected[ $key ]}, got " . ( $current[ $key ] ?? 'missing' ) . '.' );
			}
		}
	}

	public function metadata(): array {
		if ( null !== $this->metadata ) {
			return $this->metadata;
		}
		$cache_key = json_encode(
			array(
				$this->kind,
				$this->lexbor_oracle_bin,
				$this->html5ever_oracle_bin,
				$this->chrome_oracle_script,
				$this->chrome_executable,
				$this->chrome_socket,
				$this->node_bin,
			)
		);
		if ( is_string( $cache_key ) && isset( self::$metadata_cache[ $cache_key ] ) ) {
			$this->metadata = self::$metadata_cache[ $cache_key ];
			return $this->metadata;
		}

		if ( self::KIND_CHROME_CDP === $this->kind ) {
			$this->metadata = $this->chrome_metadata();
			if ( is_string( $cache_key ) ) {
				self::$metadata_cache[ $cache_key ] = $this->metadata;
			}
			return $this->metadata;
		}

		$binary = self::KIND_LEXBOR_SOURCE === $this->kind ? $this->lexbor_oracle_bin : $this->html5ever_oracle_bin;
		$metadata = array(
			'kind'   => $this->kind,
			'binary' => $binary,
		);

		if ( is_string( $binary ) && is_file( $binary ) && is_executable( $binary ) ) {
			$version = $this->run_process( array( $binary, '--version' ) );
			$decoded = json_decode( trim( $version['stdout'] ), true );
			if ( is_array( $decoded['oracle'] ?? null ) ) {
				$metadata = array_merge( $metadata, $decoded['oracle'] );
				$metadata['binary'] = $binary;
				$metadata['available'] = true;
			} else {
				$metadata['available'] = false;
				$metadata['versionError'] = trim( $version['output'] );
			}
		} else {
			$metadata['available'] = false;
		}

		$this->metadata = $metadata;
		if ( is_string( $cache_key ) ) {
			self::$metadata_cache[ $cache_key ] = $this->metadata;
		}
		return $this->metadata;
	}

	private function chrome_metadata(): array {
		$metadata = array(
			'kind'             => $this->kind,
			'engine'           => 'chrome',
			'script'           => $this->chrome_oracle_script,
			'nodeBinary'       => $this->node_bin,
			'chromeExecutable' => $this->chrome_executable,
			'chromeSocket'     => $this->chrome_socket,
		);
		if ( null === $this->chrome_oracle_script || ! is_file( $this->chrome_oracle_script ) ) {
			$metadata['available'] = false;
			$metadata['error']     = 'Chrome CDP oracle script is not available.';
			return $metadata;
		}

		if ( null !== $this->chrome_socket ) {
			$version = $this->chrome_request( array( 'command' => 'version' ) );
			$decoded = $version['decoded'] ?? null;
		} else {
			$command = array( $this->node_bin, $this->chrome_oracle_script, '--version', '--engine', 'chrome' );
			$this->append_chrome_launch_args( $command );
			$version = $this->run_process( $command );
			$decoded = json_decode( trim( $version['stdout'] ), true );
		}
		if (
			'ok' === ( $decoded['status'] ?? null ) &&
			is_array( $decoded['oracle'] ?? null ) &&
			true === ( $decoded['oracle']['available'] ?? false )
		) {
			$metadata = array_merge( $metadata, $decoded['oracle'] );
			$metadata['script'] = $this->chrome_oracle_script;
			$metadata['nodeBinary'] = $this->node_bin;
			$metadata['chromeSocket'] = $this->chrome_socket;
			$metadata['available'] = true;
		} else {
			if ( is_array( $decoded['oracle'] ?? null ) ) {
				$metadata = array_merge( $metadata, $decoded['oracle'] );
			}
			$metadata['available']    = false;
			$metadata['versionError'] = $decoded['error'] ?? trim( (string) ( $version['output'] ?? '' ) );
		}
		return $metadata;
	}

	public function replay_options(): array {
		$options = array(
			'domOracle' => $this->kind,
		);
		if ( self::KIND_LEXBOR_SOURCE === $this->kind && null !== $this->lexbor_oracle_bin ) {
			$options['lexborOracleBin'] = $this->lexbor_oracle_bin;
		}
		if ( self::KIND_HTML5EVER_SOURCE === $this->kind && null !== $this->html5ever_oracle_bin ) {
			$options['html5everOracleBin'] = $this->html5ever_oracle_bin;
		}
		if ( self::KIND_CHROME_CDP === $this->kind ) {
			$options['chromeOracleScript'] = $this->chrome_oracle_script;
			$options['chromeExecutable']   = $this->chrome_executable;
			$options['chromeSocket']       = $this->chrome_socket;
			$options['nodeBin']            = $this->node_bin;
		}
		if ( 2500 !== $this->timeout_ms ) {
			$options['oracleTimeoutMs'] = $this->timeout_ms;
		}

		return $options;
	}

	public function worker_args(): array {
		$args = array( '--dom-oracle', $this->kind );
		if ( self::KIND_LEXBOR_SOURCE === $this->kind && null !== $this->lexbor_oracle_bin ) {
			$args[] = '--lexbor-oracle-bin';
			$args[] = $this->lexbor_oracle_bin;
		}
		if ( self::KIND_HTML5EVER_SOURCE === $this->kind && null !== $this->html5ever_oracle_bin ) {
			$args[] = '--html5ever-oracle-bin';
			$args[] = $this->html5ever_oracle_bin;
		}
		if ( self::KIND_CHROME_CDP === $this->kind ) {
			if ( null !== $this->chrome_oracle_script ) {
				$args[] = '--chrome-oracle-script';
				$args[] = $this->chrome_oracle_script;
			}
			if ( null !== $this->chrome_executable ) {
				$args[] = '--chrome-executable';
				$args[] = $this->chrome_executable;
			}
			if ( null !== $this->chrome_socket ) {
				$args[] = '--chrome-socket';
				$args[] = $this->chrome_socket;
			}
			if ( 'node' !== $this->node_bin ) {
				$args[] = '--node-bin';
				$args[] = $this->node_bin;
			}
		}
		if ( 2500 !== $this->timeout_ms ) {
			$args[] = '--oracle-timeout-ms';
			$args[] = (string) $this->timeout_ms;
		}

		return $args;
	}

	public function render( string $html, string $mode, array $limits = array(), string $fragment_context = 'body' ): array {
		if ( self::KIND_CHROME_CDP === $this->kind ) {
			return $this->render_chrome( $html, $mode, $limits, $fragment_context );
		}

		return $this->render_source( $html, $mode, $limits, $fragment_context );
	}

	private function render_source( string $html, string $mode, array $limits, string $fragment_context ): array {
		$is_lexbor = self::KIND_LEXBOR_SOURCE === $this->kind;
		$label     = $is_lexbor ? 'Lexbor' : 'html5ever';
		$binary    = $is_lexbor ? $this->lexbor_oracle_bin : $this->html5ever_oracle_bin;
		if ( null === $binary || ! is_file( $binary ) || ! is_executable( $binary ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle binary is not available. Build it or pass the matching oracle binary option.',
				'failureClass' => 'oracle-unavailable',
				'oracle'       => $this->metadata(),
			);
		}

		$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-oracle-input-' );
		if ( false === $tmp ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Could not create a temporary input file for the ' . $label . ' source oracle.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
			);
		}

		try {
			if ( false === file_put_contents( $tmp, $html ) ) {
				return array(
					'status'       => TreeRenderer::STATUS_ERROR,
					'error'        => 'Could not write the temporary input file for the ' . $label . ' source oracle.',
					'failureClass' => 'oracle-renderer-error',
					'oracle'       => $this->metadata(),
				);
			}
			$proc = $this->run_process(
				array(
					$binary,
					'--mode',
					$mode,
					'--context',
					$fragment_context,
					'--max-nodes',
					(string) ( $limits['maxNodes'] ?? 3000 ),
					'--input',
					$tmp,
				)
			);
		} finally {
			@unlink( $tmp );
		}

		if ( $proc['timedOut'] ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle timed out.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
				'process'      => self::compact_process( $proc ),
			);
		}

		$decoded = json_decode( $proc['stdout'], true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['status'] ?? null ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle did not return a valid JSON result.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
				'process'      => self::compact_process( $proc ),
			);
		}

		$status = $decoded['status'];
		if ( ! in_array( $status, array( TreeRenderer::STATUS_OK, TreeRenderer::STATUS_UNSUPPORTED, TreeRenderer::STATUS_ERROR ), true ) ) {
			$status = TreeRenderer::STATUS_ERROR;
		}

		$result = array(
			'status'       => $status,
			'oracle'       => is_array( $decoded['oracle'] ?? null ) ? array_merge( $this->metadata(), $decoded['oracle'] ) : $this->metadata(),
			'nodeCount'    => $decoded['nodeCount'] ?? null,
			'process'      => self::compact_process( $proc ),
		);

		if ( TreeRenderer::STATUS_OK === $status && is_string( $decoded['treeBase64'] ?? null ) ) {
			$tree = base64_decode( $decoded['treeBase64'], true );
			if ( false === $tree ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = $label . ' source oracle returned invalid treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['tree'] = $tree;
		} elseif ( TreeRenderer::STATUS_OK === $status && is_string( $decoded['tree'] ?? null ) ) {
			$result['tree'] = $decoded['tree'];
		}
		if ( is_string( $decoded['failureClass'] ?? null ) ) {
			$result['failureClass'] = $decoded['failureClass'];
		}
		if ( is_string( $decoded['error'] ?? null ) ) {
			$result['error'] = $decoded['error'];
		}
		if ( is_array( $decoded['unsupported'] ?? null ) ) {
			$result['unsupported'] = $decoded['unsupported'];
		}

		if ( TreeRenderer::STATUS_OK === $status && ! is_string( $result['tree'] ?? null ) ) {
			$result['status']       = TreeRenderer::STATUS_ERROR;
			$result['error']        = $label . ' source oracle returned ok without a tree.';
			$result['failureClass'] = 'oracle-renderer-error';
		}

		return $result;
	}

	private function render_chrome( string $html, string $mode, array $limits, string $fragment_context ): array {
		if ( null === $this->chrome_oracle_script || ! is_file( $this->chrome_oracle_script ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Chrome CDP oracle script is not available.',
				'failureClass' => 'oracle-unavailable',
				'oracle'       => $this->metadata(),
			);
		}
		$metadata = $this->metadata();
		if ( false === ( $metadata['available'] ?? true ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $metadata['error'] ?? 'Chrome CDP oracle is unavailable.',
				'failureClass' => 'oracle-unavailable',
				'oracle'       => $metadata,
			);
		}

		$proc = $this->chrome_request(
			array(
				'command'    => 'render',
				'htmlBase64' => base64_encode( $html ),
				'mode'       => $mode,
				'context'    => $fragment_context,
				'maxNodes'   => (int) ( $limits['maxNodes'] ?? 3000 ),
			)
		);

		if ( $proc['timedOut'] ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Chrome CDP oracle timed out.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
				'process'      => self::compact_process( $proc ),
			);
		}

		$decoded = $proc['decoded'] ?? null;
		if ( ! is_array( $decoded ) || ! is_string( $decoded['status'] ?? null ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Chrome CDP oracle did not return a valid JSON result.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
				'process'      => self::compact_process( $proc ),
			);
		}

		$status = $decoded['status'];
		if ( ! in_array( $status, array( TreeRenderer::STATUS_OK, TreeRenderer::STATUS_UNSUPPORTED, TreeRenderer::STATUS_ERROR ), true ) ) {
			$status = TreeRenderer::STATUS_ERROR;
		}
		$result = array(
			'status'    => $status,
			'oracle'    => is_array( $decoded['oracle'] ?? null ) ? array_merge( $this->metadata(), $decoded['oracle'] ) : $this->metadata(),
			'nodeCount' => $decoded['nodeCount'] ?? null,
			'process'   => self::compact_process( $proc ),
		);
		if ( TreeRenderer::STATUS_OK === $status && is_string( $decoded['treeBase64'] ?? null ) ) {
			$tree = base64_decode( $decoded['treeBase64'], true );
			if ( false === $tree ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = 'Chrome CDP oracle returned invalid treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['tree'] = $tree;
		}
		if ( is_string( $decoded['failureClass'] ?? null ) ) {
			$result['failureClass'] = $decoded['failureClass'];
		}
		if ( is_string( $decoded['error'] ?? null ) ) {
			$result['error'] = $decoded['error'];
		}
		if ( is_array( $decoded['unsupported'] ?? null ) ) {
			$result['unsupported'] = $decoded['unsupported'];
		}
		if ( TreeRenderer::STATUS_OK === $status && ! is_string( $result['tree'] ?? null ) ) {
			$result['status']       = TreeRenderer::STATUS_ERROR;
			$result['error']        = 'Chrome CDP oracle returned ok without a tree.';
			$result['failureClass'] = 'oracle-renderer-error';
		}
		return $result;
	}

	private function append_chrome_launch_args( array &$command ): void {
		if ( null !== $this->chrome_executable ) {
			$command[] = '--chrome-executable';
			$command[] = $this->chrome_executable;
		}
	}

	private function chrome_server_key(): string {
		return sha1(
			json_encode(
				array(
					$this->node_bin,
					$this->chrome_oracle_script,
					$this->chrome_executable,
				),
				JSON_UNESCAPED_SLASHES
			)
		);
	}

	private function chrome_request( array $request, bool $may_retry = true ): array {
		if ( null !== $this->chrome_socket ) {
			return $this->chrome_socket_request( $request );
		}

		$key = $this->chrome_server_key();
		$started_server = false;
		if ( ! isset( self::$chrome_servers[ $key ] ) ) {
			$this->start_chrome_server( $key );
			$started_server = true;
		}
		$server = &self::$chrome_servers[ $key ];
		$request['id'] = ++self::$chrome_request_id;
		$encoded = json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		$start = microtime( true );
		$request_timeout_ms = $started_server ? max( 15000, $this->timeout_ms ) : $this->timeout_ms;
		if ( false === $encoded || false === @fwrite( $server['pipes'][0], $encoded . "\n" ) ) {
			self::stop_chrome_server( $key );
			if ( $may_retry ) {
				return $this->chrome_request( $request, false );
			}
			return array(
				'command'    => $server['command'] ?? '',
				'code'       => null,
				'timedOut'   => false,
				'durationMs' => 0,
				'stdout'     => '',
				'stderr'     => 'Could not write to Chrome CDP oracle server.',
				'output'     => 'Could not write to Chrome CDP oracle server.',
				'decoded'    => null,
			);
		}
		fflush( $server['pipes'][0] );

		while ( true ) {
			$server['stdout'] .= stream_get_contents( $server['pipes'][1] );
			$server['stderr'] .= stream_get_contents( $server['pipes'][2] );
			while ( false !== ( $newline = strpos( $server['stdout'], "\n" ) ) ) {
				$line = substr( $server['stdout'], 0, $newline );
				$server['stdout'] = substr( $server['stdout'], $newline + 1 );
				$decoded = json_decode( $line, true );
				if ( is_array( $decoded ) && ( $decoded['id'] ?? null ) === $request['id'] ) {
					$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
					return array(
						'command'    => $server['command'],
						'code'       => null,
						'timedOut'   => false,
						'durationMs' => $duration_ms,
						'stdout'     => $line,
						'stderr'     => $server['stderr'],
						'output'     => $line . $server['stderr'],
						'decoded'    => $decoded,
					);
				}
			}

			$status = proc_get_status( $server['process'] );
			if ( ! $status['running'] ) {
				$stderr = $server['stderr'];
				$command = $server['command'];
				self::stop_chrome_server( $key );
				if ( $may_retry ) {
					return $this->chrome_request( $request, false );
				}
				return array(
					'command'    => $command,
					'code'       => $status['exitcode'] ?? null,
					'timedOut'   => false,
					'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
					'stdout'     => '',
					'stderr'     => $stderr,
					'output'     => $stderr,
					'decoded'    => null,
				);
			}
			if ( ( microtime( true ) - $start ) * 1000 > $request_timeout_ms ) {
				$stderr = $server['stderr'];
				$command = $server['command'];
				self::stop_chrome_server( $key );
				return array(
					'command'    => $command,
					'code'       => null,
					'timedOut'   => true,
					'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
					'stdout'     => '',
					'stderr'     => $stderr,
					'output'     => $stderr,
					'decoded'    => null,
				);
			}
			usleep( 1000 );
		}
	}

	private function chrome_socket_request( array $request, ?int $timeout_override_ms = null ): array {
		$request['id'] = ++self::$chrome_request_id;
		$encoded = json_encode( $request, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		$start = microtime( true );
		$timeout_ms = max( 1, $timeout_override_ms ?? $this->timeout_ms );
		$socket_uri = 0 === strpos( (string) $this->chrome_socket, 'unix://' )
			? $this->chrome_socket
			: 'unix://' . $this->chrome_socket;
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client( $socket_uri, $errno, $error, max( 1.0, $timeout_ms / 1000 ) );
		if ( ! is_resource( $socket ) ) {
			return array(
				'command'    => 'chrome-cdp socket ' . $this->chrome_socket,
				'code'       => null,
				'timedOut'   => false,
				'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'stdout'     => '',
				'stderr'     => "Could not connect to Chrome CDP socket ({$errno}): {$error}",
				'output'     => $error,
				'decoded'    => null,
			);
		}

		$seconds = intdiv( $timeout_ms, 1000 );
		$microseconds = ( $timeout_ms % 1000 ) * 1000;
		stream_set_timeout( $socket, $seconds, $microseconds );
		if ( false === $encoded || false === @fwrite( $socket, $encoded . "\n" ) ) {
			fclose( $socket );
			return array(
				'command'    => 'chrome-cdp socket ' . $this->chrome_socket,
				'code'       => null,
				'timedOut'   => false,
				'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'stdout'     => '',
				'stderr'     => 'Could not write to Chrome CDP socket.',
				'output'     => 'Could not write to Chrome CDP socket.',
				'decoded'    => null,
			);
		}
		fflush( $socket );

		$line = false;
		$decoded = null;
		while ( false !== ( $candidate = fgets( $socket ) ) ) {
			$candidate_decoded = json_decode( trim( $candidate ), true );
			if ( is_array( $candidate_decoded ) && ( $candidate_decoded['id'] ?? null ) === $request['id'] ) {
				$line = trim( $candidate );
				$decoded = $candidate_decoded;
				break;
			}
		}
		$stream_metadata = stream_get_meta_data( $socket );
		fclose( $socket );
		$timed_out = (bool) ( $stream_metadata['timed_out'] ?? false );
		return array(
			'command'    => 'chrome-cdp socket ' . $this->chrome_socket,
			'code'       => null,
			'timedOut'   => $timed_out,
			'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'stdout'     => false === $line ? '' : $line,
			'stderr'     => '',
			'output'     => false === $line ? '' : $line,
			'decoded'    => $decoded,
		);
	}

	/**
	 * Starts one Chrome daemon for an entire runner/minimizer lifetime. Worker
	 * subprocesses connect to its Unix socket, preserving process isolation
	 * without relaunching the browser for every seed batch.
	 */
	public function start_run_service( string $output_dir ): void {
		if ( self::KIND_CHROME_CDP !== $this->kind || null !== $this->chrome_socket ) {
			return;
		}
		if ( null === $this->chrome_oracle_script || ! is_file( $this->chrome_oracle_script ) ) {
			throw new \RuntimeException( 'Chrome CDP oracle script is not available.' );
		}

		$socket_path = sys_get_temp_dir() . '/html-api-fuzz-chrome-' . substr( sha1( $output_dir . ':' . getmypid() ), 0, 16 ) . '.sock';
		@unlink( $socket_path );
		$command = array(
			$this->node_bin,
			$this->chrome_oracle_script,
			'--serve',
			'--engine',
			'chrome',
			'--socket',
			$socket_path,
		);
		$this->append_chrome_launch_args( $command );
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $spec, $pipes, repo_root() );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start the persistent Chrome CDP run service.' );
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$key = sha1( $socket_path );
		self::$chrome_run_services[ $key ] = array(
			'process'    => $process,
			'pipes'      => $pipes,
			'command'    => command_string( $command ),
			'socketPath' => $socket_path,
		);
		$this->run_service_key = $key;
		$this->chrome_socket   = $socket_path;
		$this->metadata        = null;
		self::register_chrome_shutdown();

		$deadline = microtime( true ) + max( 15.0, $this->timeout_ms / 1000 );
		while ( ! file_exists( $socket_path ) ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] || microtime( true ) >= $deadline ) {
				$stderr = stream_get_contents( $pipes[2] );
				self::stop_chrome_run_service( $key );
				$this->run_service_key = null;
				$this->chrome_socket = null;
				throw new \RuntimeException( 'Persistent Chrome CDP service did not become ready: ' . trim( $stderr ) );
			}
			usleep( 10000 );
		}

		$probe = $this->chrome_socket_request( array( 'command' => 'version' ), max( 15000, $this->timeout_ms ) );
		if (
			'ok' !== ( $probe['decoded']['status'] ?? null ) ||
			! is_array( $probe['decoded']['oracle'] ?? null ) ||
			true !== ( $probe['decoded']['oracle']['available'] ?? false )
		) {
			self::stop_chrome_run_service( $key );
			$this->run_service_key = null;
			$this->chrome_socket = null;
			throw new \RuntimeException( 'Persistent Chrome CDP service failed its readiness probe: ' . trim( (string) ( $probe['output'] ?? '' ) ) );
		}
	}

	public function stop_run_service(): void {
		if ( null === $this->run_service_key ) {
			return;
		}
		self::stop_chrome_run_service( $this->run_service_key );
		$this->run_service_key = null;
	}

	private function start_chrome_server( string $key ): void {
		$command = array(
			$this->node_bin,
			$this->chrome_oracle_script,
			'--serve',
			'--engine',
			'chrome',
		);
		$this->append_chrome_launch_args( $command );
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $command, $spec, $pipes, repo_root() );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start Chrome CDP oracle server.' );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		self::$chrome_servers[ $key ] = array(
			'process' => $process,
			'pipes'   => $pipes,
			'command' => command_string( $command ),
			'stdout'  => '',
			'stderr'  => '',
		);
		self::register_chrome_shutdown();
	}

	private static function register_chrome_shutdown(): void {
		if ( ! self::$chrome_shutdown_registered ) {
			register_shutdown_function( array( __CLASS__, 'shutdown_chrome_processes' ) );
			self::$chrome_shutdown_registered = true;
		}
	}

	public static function shutdown_chrome_processes(): void {
		foreach ( array_keys( self::$chrome_servers ) as $key ) {
			self::stop_chrome_server( $key );
		}
		foreach ( array_keys( self::$chrome_run_services ) as $key ) {
			self::stop_chrome_run_service( $key );
		}
	}

	private static function stop_chrome_server( string $key ): void {
		if ( ! isset( self::$chrome_servers[ $key ] ) ) {
			return;
		}
		$server = self::$chrome_servers[ $key ];
		unset( self::$chrome_servers[ $key ] );
		if ( isset( $server['pipes'][0] ) && is_resource( $server['pipes'][0] ) ) {
			@fwrite( $server['pipes'][0], "{\"command\":\"shutdown\"}\n" );
			@fclose( $server['pipes'][0] );
		}
		self::await_or_terminate( $server['process'] );
		foreach ( array( 1, 2 ) as $pipe_index ) {
			if ( isset( $server['pipes'][ $pipe_index ] ) && is_resource( $server['pipes'][ $pipe_index ] ) ) {
				@fclose( $server['pipes'][ $pipe_index ] );
			}
		}
		@proc_close( $server['process'] );
	}

	private static function stop_chrome_run_service( string $key ): void {
		if ( ! isset( self::$chrome_run_services[ $key ] ) ) {
			return;
		}
		$service = self::$chrome_run_services[ $key ];
		unset( self::$chrome_run_services[ $key ] );
		$socket_path = $service['socketPath'];
		$socket = @stream_socket_client( 'unix://' . $socket_path, $errno, $error, 0.5 );
		if ( is_resource( $socket ) ) {
			@fwrite( $socket, "{\"id\":0,\"command\":\"shutdown\"}\n" );
			@fflush( $socket );
			@fclose( $socket );
		}
		self::await_or_terminate( $service['process'] );
		foreach ( array( 1, 2 ) as $pipe_index ) {
			if ( isset( $service['pipes'][ $pipe_index ] ) && is_resource( $service['pipes'][ $pipe_index ] ) ) {
				@fclose( $service['pipes'][ $pipe_index ] );
			}
		}
		@proc_close( $service['process'] );
		@unlink( $socket_path );
	}

	private static function await_or_terminate( $process ): void {
		$deadline = microtime( true ) + 3.0;
		do {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				return;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		proc_terminate( $process );
		usleep( 200000 );
		$status = proc_get_status( $process );
		if ( $status['running'] ) {
			proc_terminate( $process, 9 );
		}
	}

	private function run_process( array $command ): array {
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $spec, $pipes, repo_root() );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start oracle subprocess.' );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$start     = microtime( true );
		$timed_out = false;

		while ( true ) {
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}

			if ( ( microtime( true ) - $start ) * 1000 > $this->timeout_ms ) {
				$timed_out = true;
				proc_terminate( $process );
				usleep( 200000 );
				$status = proc_get_status( $process );
				if ( $status['running'] ) {
					proc_terminate( $process, 9 );
				}
				break;
			}

			usleep( 10000 );
		}

		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		if ( $timed_out ) {
			$exit_code = null;
		}

		return array(
			'command'    => command_string( $command ),
			'code'       => $exit_code,
			'timedOut'   => $timed_out,
			'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'stdout'     => $stdout,
			'stderr'     => $stderr,
			'output'     => $stdout . $stderr,
		);
	}

	private static function compact_process( array $process ): array {
		return array(
			'command'    => $process['command'] ?? null,
			'code'       => $process['code'] ?? null,
			'timedOut'   => $process['timedOut'] ?? null,
			'durationMs' => $process['durationMs'] ?? null,
			'stderrTail' => substr( (string) ( $process['stderr'] ?? '' ), -1000 ),
		);
	}
}
