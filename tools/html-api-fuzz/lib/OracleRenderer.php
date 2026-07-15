<?php
namespace HtmlApiFuzz;

class OracleRenderer {
	public const KIND_PHP_DOM       = 'php-dom';
	public const KIND_LEXBOR_SOURCE = 'lexbor-source';

	private string $kind;
	private ?string $lexbor_oracle_bin;
	private int $timeout_ms;
	private ?array $metadata = null;

	private function __construct( string $kind, ?string $lexbor_oracle_bin = null, int $timeout_ms = 2500 ) {
		$this->kind              = $kind;
		$this->lexbor_oracle_bin = $lexbor_oracle_bin;
		$this->timeout_ms        = $timeout_ms;
	}

	public static function from_options( array $options ): self {
		$kind = option_string( $options, 'dom-oracle', self::KIND_PHP_DOM );
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			throw new \InvalidArgumentException( 'Expected --dom-oracle to be php-dom or lexbor-source.' );
		}

		$lexbor_oracle_bin = option_string( $options, 'lexbor-oracle-bin', getenv( 'HTML_API_FUZZ_LEXBOR_ORACLE' ) ?: null );
		if ( self::KIND_LEXBOR_SOURCE === $kind && ( null === $lexbor_oracle_bin || '' === $lexbor_oracle_bin ) ) {
			$lexbor_oracle_bin = repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle';
		}

		return new self(
			$kind,
			$lexbor_oracle_bin,
			option_int( $options, 'oracle-timeout-ms', 2500 )
		);
	}

	public static function kinds(): array {
		return array( self::KIND_PHP_DOM, self::KIND_LEXBOR_SOURCE );
	}

	/** Return reasons the current oracle cannot faithfully replay recorded output. */
	public static function identity_mismatches( $recorded, array $current ): array {
		if ( ! is_array( $recorded ) ) {
			return array( 'recorded oracle metadata is missing' );
		}

		$recorded_kind = $recorded['kind'] ?? null;
		$current_kind  = $current['kind'] ?? null;
		if ( ! is_string( $recorded_kind ) || ! in_array( $recorded_kind, self::kinds(), true ) ) {
			return array( 'recorded oracle kind is invalid' );
		}
		if ( ! is_string( $current_kind ) || ! in_array( $current_kind, self::kinds(), true ) ) {
			return array( 'current oracle kind is invalid' );
		}
		if ( $recorded_kind !== $current_kind ) {
			return array( "oracle kind differs (recorded {$recorded_kind}, current {$current_kind})" );
		}
		if ( self::KIND_LEXBOR_SOURCE !== $current_kind ) {
			return array();
		}

		$mismatches = array();
		if ( false === ( $current['available'] ?? true ) ) {
			$mismatches[] = 'current Lexbor oracle is unavailable';
		}
		if ( array_key_exists( 'versionError', $current ) ) {
			$mismatches[] = 'current Lexbor oracle failed self-verification';
		}
		foreach (
			array(
				'lexborCommit' => array( '/^[0-9a-f]{40}$/', 'Lexbor commit' ),
				'binarySha256' => array( '/^[0-9a-f]{64}$/', 'Lexbor binary SHA-256' ),
			) as $field => $validation
		) {
			list( $pattern, $label ) = $validation;
			$recorded_value = $recorded[ $field ] ?? null;
			$current_value  = $current[ $field ] ?? null;
			if ( ! is_string( $recorded_value ) || ! preg_match( $pattern, $recorded_value ) ) {
				$mismatches[] = "recorded {$label} is missing or invalid";
				continue;
			}
			if ( ! is_string( $current_value ) || ! preg_match( $pattern, $current_value ) ) {
				$mismatches[] = "current {$label} is missing or invalid";
				continue;
			}
			if ( $recorded_value !== $current_value ) {
				$mismatches[] = "{$label} differs";
			}
		}

		return $mismatches;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function is_php_dom(): bool {
		return self::KIND_PHP_DOM === $this->kind;
	}

	public function metadata(): array {
		if ( null !== $this->metadata ) {
			return $this->metadata;
		}

		if ( self::KIND_PHP_DOM === $this->kind ) {
			$this->metadata = array(
				'kind'              => self::KIND_PHP_DOM,
				'phpVersion'        => PHP_VERSION,
				'domHTMLDocument'   => class_exists( 'Dom\\HTMLDocument' ),
			);
			return $this->metadata;
		}

		$metadata = array(
			'kind'   => self::KIND_LEXBOR_SOURCE,
			'binary' => $this->lexbor_oracle_bin,
		);

		if ( is_string( $this->lexbor_oracle_bin ) && is_file( $this->lexbor_oracle_bin ) && is_executable( $this->lexbor_oracle_bin ) ) {
			$metadata['binarySha256'] = hash_file( 'sha256', $this->lexbor_oracle_bin );
			$manifest_path = dirname( $this->lexbor_oracle_bin ) . '/build-manifest.json';
			$manifest      = read_json_file( $manifest_path );
			if ( is_array( $manifest ) ) {
				$metadata['buildManifest'] = $manifest;
			}
			$version = $this->run_process( array( $this->lexbor_oracle_bin, '--version' ) );
			$decoded = json_decode( trim( $version['stdout'] ), true );
			if ( is_array( $decoded['oracle'] ?? null ) ) {
				$metadata = array_merge( $metadata, $decoded['oracle'] );
				$metadata['binary'] = $this->lexbor_oracle_bin;
				if (
					is_array( $manifest ) &&
					( ( $manifest['binarySha256'] ?? null ) !== $metadata['binarySha256'] ||
						( $manifest['resolvedCommit'] ?? null ) !== ( $metadata['lexborCommit'] ?? null ) )
				) {
					$metadata['versionError'] = 'Lexbor build manifest does not match the executable hash and embedded commit.';
				}
			} else {
				$metadata['versionError'] = trim( $version['output'] );
			}
		} else {
			$metadata['available'] = false;
		}

		$this->metadata = $metadata;
		return $this->metadata;
	}

	public function replay_options(): array {
		$options = array(
			'domOracle' => $this->kind,
		);
		if ( self::KIND_LEXBOR_SOURCE === $this->kind && null !== $this->lexbor_oracle_bin ) {
			$options['lexborOracleBin'] = $this->lexbor_oracle_bin;
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
		if ( 2500 !== $this->timeout_ms ) {
			$args[] = '--oracle-timeout-ms';
			$args[] = (string) $this->timeout_ms;
		}

		return $args;
	}

	public function render( string $html, string $mode, array $limits = array(), string $fragment_context = 'body' ): array {
		if ( self::KIND_PHP_DOM === $this->kind ) {
			$result = TreeRenderer::render_dom( $html, $mode, $limits, $fragment_context );
			$result['oracle'] = $this->metadata();
			return $result;
		}

		return $this->render_lexbor_source( $html, $mode, $limits, $fragment_context );
	}

	private function render_lexbor_source( string $html, string $mode, array $limits, string $fragment_context ): array {
		if ( null === $this->lexbor_oracle_bin || ! is_file( $this->lexbor_oracle_bin ) || ! is_executable( $this->lexbor_oracle_bin ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Lexbor source oracle binary is not available. Build it or pass --lexbor-oracle-bin.',
				'failureClass' => 'oracle-unavailable',
				'oracle'       => $this->metadata(),
			);
		}

		$tmp = tempnam( sys_get_temp_dir(), 'html-api-fuzz-lexbor-input-' );
		if ( false === $tmp ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Could not create a temporary input file for the Lexbor source oracle.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
			);
		}

		try {
			if ( false === file_put_contents( $tmp, $html ) ) {
				return array(
					'status'       => TreeRenderer::STATUS_ERROR,
					'error'        => 'Could not write the temporary input file for the Lexbor source oracle.',
					'failureClass' => 'oracle-renderer-error',
					'oracle'       => $this->metadata(),
				);
			}
			$proc = $this->run_process(
				array(
					$this->lexbor_oracle_bin,
					'--mode',
					$mode,
					'--context',
					$fragment_context,
					'--max-nodes',
					(string) ( $limits['maxNodes'] ?? 3000 ),
					'--max-depth',
					(string) ( $limits['maxDepth'] ?? 512 ),
					'--max-tree-bytes',
					(string) ( $limits['maxTreeBytes'] ?? 16777216 ),
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
				'error'        => 'Lexbor source oracle timed out.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $this->metadata(),
				'process'      => self::compact_process( $proc ),
			);
		}

		$decoded = json_decode( $proc['stdout'], true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['status'] ?? null ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Lexbor source oracle did not return a valid JSON result.',
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
				$result['error']        = 'Lexbor source oracle returned invalid treeBase64.';
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
			$result['error']        = 'Lexbor source oracle returned ok without a tree.';
			$result['failureClass'] = 'oracle-renderer-error';
		}

		return $result;
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
