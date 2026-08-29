<?php
namespace HtmlApiFuzz;

class OracleRenderer {
	public const KIND_LEXBOR_SOURCE     = 'lexbor-source';
	public const KIND_HTML5EVER_SOURCE  = 'html5ever-source';
	public const KIND_CHROME_CDP        = 'chrome-cdp';
	private const CHROME_LIFECYCLE_OWNER_PREFIX = 'html-api-fuzz-chrome-owner-';
	private const CHROME_LIFECYCLE_OWNER_FILE = 'owner-token';
	private const CHROME_LIFECYCLE_RECORD_FILE = 'lifecycle.json';
	private const CHROME_LIFECYCLE_TEMP_FILE = 'lifecycle.json.tmp';
	private const SOURCE_ERROR_FAILURE_CLASSES = array(
		'oracle-parse-error',
		'node-limit-exceeded',
		'oracle-renderer-error',
	);
	private const CHROME_ERROR_FAILURE_CLASSES = array(
		'oracle-unavailable',
		'oracle-renderer-error',
		'node-limit-exceeded',
	);

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
		if ( array_key_exists( 'chrome-socket', $options ) && ( true === $options['chrome-socket'] || '' === trim( (string) $options['chrome-socket'] ) ) ) {
			throw new \InvalidArgumentException( 'Expected --chrome-socket to be a non-empty path.' );
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
		if ( self::KIND_CHROME_CDP === $kind && is_string( $chrome_oracle_script ) && '' !== $chrome_oracle_script ) {
			$is_absolute = DIRECTORY_SEPARATOR === substr( $chrome_oracle_script, 0, 1 ) ||
				( '\\' === DIRECTORY_SEPARATOR && 1 === preg_match( '/^[A-Za-z]:[\\\\\\/]/', $chrome_oracle_script ) );
			$canonical = realpath( $is_absolute ? $chrome_oracle_script : repo_root() . DIRECTORY_SEPARATOR . $chrome_oracle_script );
			if ( false !== $canonical ) {
				$chrome_oracle_script = $canonical;
			}
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
		foreach ( array( 'kind', 'lexborCommit', 'html5everVersion', 'html5everChecksum', 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain', 'pinnedChromeVersion' ) as $key ) {
			if ( ! is_string( $expected[ $key ] ?? null ) || '' === $expected[ $key ] ) {
				continue;
			}
			if ( ( $current[ $key ] ?? null ) !== $expected[ $key ] ) {
				throw new \RuntimeException( "Replay oracle mismatch for {$key}: expected {$expected[ $key ]}, got " . ( $current[ $key ] ?? 'missing' ) . '.' );
			}
		}
	}

	public static function replay_safe_document( array $document ): array {
		foreach ( $document as $key => $value ) {
			if ( in_array( $key, array( 'chromeSocket', 'browserPid', 'browserInstanceId', 'serverPid' ), true ) ) {
				unset( $document[ $key ] );
			} elseif ( is_array( $value ) ) {
				$document[ $key ] = self::replay_safe_document( $value );
			}
		}
		return $document;
	}

	public static function replay_safe_metadata( array $metadata ): array {
		return self::replay_safe_document( $metadata );
	}

	public function replay_metadata(): array {
		return self::replay_safe_metadata( $this->metadata() );
	}

	private function source_identity_error( array $oracle ): ?string {
		if ( $this->kind !== ( $oracle['kind'] ?? null ) ) {
			return 'reported kind ' . ( is_scalar( $oracle['kind'] ?? null ) ? (string) $oracle['kind'] : 'missing' ) . " instead of {$this->kind}";
		}
		if ( true !== ( $oracle['available'] ?? null ) ) {
			return 'did not report available=true';
		}

		if ( self::KIND_LEXBOR_SOURCE === $this->kind ) {
			$commit_path = repo_root() . '/tools/html-api-fuzz/oracles/lexbor/COMMIT';
			$expected_commit = trim( (string) @file_get_contents( $commit_path ) );
			if ( '' === $expected_commit || $expected_commit !== ( $oracle['lexborCommit'] ?? null ) ) {
				return 'reported a Lexbor commit that does not match the tracked COMMIT pin';
			}
			if ( ! is_string( $oracle['lexborVersion'] ?? null ) || '' === $oracle['lexborVersion'] ) {
				return 'did not report a Lexbor version';
			}
			return null;
		}

		$lock_path = repo_root() . '/tools/html-api-fuzz/oracles/html5ever/Cargo.lock';
		$html5ever = self::cargo_locked_package( $lock_path, 'html5ever' );
		$rcdom = self::cargo_locked_package( $lock_path, 'markup5ever_rcdom' );
		$toolchain_path = repo_root() . '/tools/html-api-fuzz/oracles/html5ever/rust-toolchain.toml';
		$toolchain = (string) @file_get_contents( $toolchain_path );
		preg_match( '/^channel\s*=\s*"([^"]+)"/m', $toolchain, $toolchain_match );
		$expected = array(
			'html5everVersion'          => $html5ever['version'] ?? null,
			'html5everChecksum'         => $html5ever['checksum'] ?? null,
			'markup5everRcdomVersion'   => $rcdom['version'] ?? null,
			'markup5everRcdomChecksum'  => $rcdom['checksum'] ?? null,
			'rustToolchain'              => $toolchain_match[1] ?? null,
		);
		foreach ( $expected as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value || $value !== ( $oracle[ $key ] ?? null ) ) {
				return "reported {$key} that does not match the checked-in pin";
			}
		}
		return null;
	}

	private function trusted_source_metadata( array $oracle, string $binary ): array {
		$metadata = array(
			'kind'      => $this->kind,
			'binary'    => $binary,
			'available' => true,
		);
		$identity_fields = self::KIND_LEXBOR_SOURCE === $this->kind
			? array( 'lexborCommit', 'lexborVersion' )
			: array( 'html5everVersion', 'html5everChecksum', 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain' );
		foreach ( $identity_fields as $field ) {
			$metadata[ $field ] = $oracle[ $field ];
		}
		return $metadata;
	}

	private static function cargo_locked_package( string $path, string $name ): ?array {
		$lock = @file_get_contents( $path );
		if ( false === $lock || ! preg_match_all( '/\[\[package\]\]\s*(.*?)(?=\n\[\[package\]\]|\z)/s', $lock, $packages ) ) {
			return null;
		}
		foreach ( $packages[1] as $package ) {
			if ( ! preg_match( '/^name\s*=\s*"([^"]+)"/m', $package, $package_name ) || $name !== $package_name[1] ) {
				continue;
			}
			preg_match( '/^version\s*=\s*"([^"]+)"/m', $package, $version );
			preg_match( '/^checksum\s*=\s*"([^"]+)"/m', $package, $checksum );
			return array(
				'version'  => $version[1] ?? null,
				'checksum' => $checksum[1] ?? null,
			);
		}
		return null;
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
			$identity_error = is_array( $decoded['oracle'] ?? null ) ? $this->source_identity_error( $decoded['oracle'] ) : 'did not return oracle metadata';
			if (
				! $version['timedOut'] &&
				0 === $version['code'] &&
				'ok' === ( $decoded['status'] ?? null ) &&
				null === $identity_error
			) {
				$metadata = $this->trusted_source_metadata( $decoded['oracle'], $binary );
			} else {
				$metadata['available'] = false;
				$metadata['versionError'] = 'Invalid source oracle version response: ' . ( $identity_error ?? 'expected status=ok and exit code 0' ) . '. ' . trim( $version['output'] );
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
		$metadata = $this->local_chrome_metadata();
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
		$require_live = null !== $this->chrome_socket;
		$identity_error = is_array( $decoded['oracle'] ?? null )
			? $this->chrome_identity_error( $decoded['oracle'], $require_live )
			: 'did not return oracle metadata';
		if (
			( $require_live || ( ! $version['timedOut'] && 0 === $version['code'] ) ) &&
			'ok' === ( $decoded['status'] ?? null ) &&
			null === $identity_error
		) {
			return $this->trusted_chrome_metadata( $decoded['oracle'], $require_live );
		}

		if ( ! $require_live && ( $version['timedOut'] ?? false ) ) {
			$reason = 'version command timed out';
		} elseif ( ! $require_live && 0 !== ( $version['code'] ?? null ) ) {
			$reason = 'version command exited with code ' . ( null === ( $version['code'] ?? null ) ? 'unknown' : $version['code'] );
		} elseif ( ! is_array( $decoded ) ) {
			$reason = 'version command did not return a JSON object';
		} elseif ( 'ok' !== ( $decoded['status'] ?? null ) ) {
			$reason = 'version command did not report status=ok';
		} else {
			$reason = $identity_error ?? 'invalid version response';
		}
		$reported_error = is_string( $decoded['error'] ?? null ) ? $decoded['error'] : trim( (string) ( $version['output'] ?? '' ) );
		$details = '' === $reported_error ? $reason : $reason . ': ' . $reported_error;
		$metadata['available'] = false;
		$metadata['versionError'] = 'Invalid Chrome CDP oracle version response: ' . self::bounded_chrome_protocol_reason( $details ) . '.';
		return $metadata;
	}

	private static function canonical_chrome_path( ?string $path ): ?string {
		if ( null === $path || '' === trim( $path ) ) {
			return null;
		}
		$is_absolute = DIRECTORY_SEPARATOR === substr( $path, 0, 1 ) ||
			( '\\' === DIRECTORY_SEPARATOR && 1 === preg_match( '/^[A-Za-z]:[\\\\\\\/]/', $path ) );
		$absolute = $is_absolute ? $path : repo_root() . DIRECTORY_SEPARATOR . $path;
		$canonical = realpath( $absolute );
		return false === $canonical ? $absolute : $canonical;
	}

	private static function pinned_chrome_version(): ?string {
		$version = trim( (string) @file_get_contents( repo_root() . '/tools/html-api-fuzz/oracles/chrome/VERSION' ) );
		return '' === $version ? null : $version;
	}

	private function expected_chrome_executable(): ?string {
		if ( null !== $this->chrome_executable && '' !== trim( $this->chrome_executable ) ) {
			return self::canonical_chrome_path( $this->chrome_executable );
		}
		if ( null === $this->chrome_oracle_script ) {
			return null;
		}

		$architecture = strtolower( php_uname( 'm' ) );
		if ( 'Darwin' === PHP_OS_FAMILY && in_array( $architecture, array( 'arm64', 'aarch64' ), true ) ) {
			$platform = 'mac-arm64';
			$archive = 'chrome-mac-arm64';
			$relative = 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
		} elseif ( 'Darwin' === PHP_OS_FAMILY && in_array( $architecture, array( 'x86_64', 'amd64', 'x64' ), true ) ) {
			$platform = 'mac-x64';
			$archive = 'chrome-mac-x64';
			$relative = 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
		} elseif ( 'Linux' === PHP_OS_FAMILY && in_array( $architecture, array( 'x86_64', 'amd64', 'x64' ), true ) ) {
			$platform = 'linux64';
			$archive = 'chrome-linux64';
			$relative = 'chrome';
		} else {
			return null;
		}

		$install_root = getenv( 'HTML_API_FUZZ_CHROME_INSTALL_ROOT' );
		if ( false === $install_root || '' === trim( $install_root ) ) {
			$install_root = dirname( $this->chrome_oracle_script ) . '/.chrome-for-testing';
		}
		$version = self::pinned_chrome_version();
		return null === $version ? null : self::canonical_chrome_path( $install_root . '/' . $version . '/' . $platform . '/' . $archive . '/' . $relative );
	}

	private function local_chrome_metadata(): array {
		return array(
			'kind'                 => self::KIND_CHROME_CDP,
			'engine'               => 'chrome',
			'pinnedChromeVersion'  => self::pinned_chrome_version(),
			'script'               => self::canonical_chrome_path( $this->chrome_oracle_script ),
			'nodeBinary'           => $this->node_bin,
			'chromeExecutable'     => $this->expected_chrome_executable(),
			'chromeSocket'         => $this->chrome_socket,
		);
	}

	private static function has_live_chrome_identity( array $oracle ): bool {
		foreach ( array( 'cdpProtocolVersion', 'browserPid', 'browserInstanceId' ) as $key ) {
			if ( array_key_exists( $key, $oracle ) ) {
				return true;
			}
		}
		return false;
	}

	private function chrome_identity_error( array $oracle, bool $require_live ): ?string {
		$pin = self::pinned_chrome_version();
		$exact = array(
			'kind'                 => self::KIND_CHROME_CDP,
			'engine'               => 'chrome',
			'pinnedChromeVersion'  => $pin,
			'chromeVersion'        => $pin,
			'browserVersion'       => $pin,
			'cdpTransport'         => 'remote-debugging-websocket',
		);
		foreach ( $exact as $key => $expected ) {
			if ( ! is_string( $expected ) || '' === $expected || ! is_string( $oracle[ $key ] ?? null ) || $expected !== $oracle[ $key ] ) {
				return "reported invalid {$key}";
			}
		}
		if ( true !== ( $oracle['available'] ?? null ) ) {
			return 'did not report available=true';
		}
		if ( ! is_string( $oracle['nodeVersion'] ?? null ) || '' === trim( $oracle['nodeVersion'] ) ) {
			return 'did not report a non-empty nodeVersion';
		}

		$expected_script = self::canonical_chrome_path( $this->chrome_oracle_script );
		$reported_script = is_string( $oracle['script'] ?? null ) ? self::canonical_chrome_path( $oracle['script'] ) : null;
		if ( null === $expected_script || ! is_file( $expected_script ) || $expected_script !== $reported_script ) {
			return 'reported a script path that does not match the configured oracle script';
		}
		$expected_executable = $this->expected_chrome_executable();
		$reported_executable = is_string( $oracle['chromeExecutable'] ?? null ) ? self::canonical_chrome_path( $oracle['chromeExecutable'] ) : null;
		if (
			null === $expected_executable || ! is_file( $expected_executable ) || ! is_executable( $expected_executable ) ||
			$expected_executable !== $reported_executable
		) {
			return 'reported a Chrome executable that does not match the configured executable';
		}

		$validate_live = $require_live || self::has_live_chrome_identity( $oracle );
		if ( $validate_live ) {
			if ( ! is_string( $oracle['cdpProtocolVersion'] ?? null ) || '' === trim( $oracle['cdpProtocolVersion'] ) ) {
				return 'did not report a non-empty cdpProtocolVersion';
			}
			if ( ! is_int( $oracle['browserPid'] ?? null ) || $oracle['browserPid'] < 1 ) {
				return 'did not report a positive integer browserPid';
			}
			if ( ! is_string( $oracle['browserInstanceId'] ?? null ) || '' === trim( $oracle['browserInstanceId'] ) ) {
				return 'did not report a non-empty browserInstanceId';
			}
		}
		return null;
	}

	private function trusted_chrome_metadata( array $oracle, bool $include_live ): array {
		$metadata = $this->local_chrome_metadata();
		$metadata['available'] = true;
		$metadata['chromeVersion'] = self::pinned_chrome_version();
		$metadata['browserVersion'] = self::pinned_chrome_version();
		$metadata['cdpTransport'] = 'remote-debugging-websocket';
		$metadata['nodeVersion'] = $oracle['nodeVersion'];
		if ( $include_live ) {
			$metadata['cdpProtocolVersion'] = $oracle['cdpProtocolVersion'];
			$metadata['browserPid'] = $oracle['browserPid'];
			$metadata['browserInstanceId'] = $oracle['browserInstanceId'];
		}
		return $metadata;
	}

	private static function bounded_chrome_protocol_reason( string $reason ): string {
		$reason = trim( preg_replace( '/\s+/', ' ', $reason ) ?? $reason );
		return substr( $reason, 0, 512 );
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
		$metadata = $this->metadata();
		if ( true !== ( $metadata['available'] ?? false ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $metadata['versionError'] ?? $label . ' source oracle failed identity validation.',
				'failureClass' => 'oracle-unavailable',
				'oracle'       => $metadata,
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
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}
		if ( 0 !== $proc['code'] ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle process exited with code ' . ( null === $proc['code'] ? 'unknown' : $proc['code'] ) . '.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}

		$decoded = json_decode( $proc['stdout'], true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle did not return a valid JSON result.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}

		$status = $decoded['status'] ?? null;
		$identity_error = is_array( $decoded['oracle'] ?? null ) ? $this->source_identity_error( $decoded['oracle'] ) : 'did not return oracle metadata';
		if ( null === $identity_error && $this->trusted_source_metadata( $decoded['oracle'], $binary ) !== $metadata ) {
			$identity_error = 'reported identity changed after version validation';
		}
		if (
			! in_array( $status, array( TreeRenderer::STATUS_OK, TreeRenderer::STATUS_UNSUPPORTED, TreeRenderer::STATUS_ERROR ), true ) ||
			null !== $identity_error ||
			! is_int( $decoded['nodeCount'] ?? null ) ||
			$decoded['nodeCount'] < 0
		) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => $label . ' source oracle returned an invalid protocol result: ' . ( $identity_error ?? 'invalid status or nodeCount' ) . '.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}

		$result = array(
			'status'       => $status,
			'oracle'       => $metadata,
			'nodeCount'    => $decoded['nodeCount'],
			'process'      => self::compact_process( $proc ),
		);

		if ( TreeRenderer::STATUS_OK === $status ) {
			if ( ! is_string( $decoded['treeBase64'] ?? null ) ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = $label . ' source oracle returned ok without treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$tree = base64_decode( $decoded['treeBase64'], true );
			if ( false === $tree ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = $label . ' source oracle returned invalid treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			if ( array_key_exists( 'tree', $decoded ) && ( ! is_string( $decoded['tree'] ) || $decoded['tree'] !== $tree ) ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = $label . ' source oracle returned disagreeing tree and treeBase64 values.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['tree'] = $tree;
		} elseif ( TreeRenderer::STATUS_UNSUPPORTED === $status ) {
			if (
				'oracle-unsupported' !== ( $decoded['failureClass'] ?? null ) ||
				! is_string( $decoded['unsupported']['message'] ?? null )
			) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = $label . ' source oracle returned malformed unsupported details.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['failureClass'] = $decoded['failureClass'];
			$result['unsupported']  = array( 'message' => $decoded['unsupported']['message'] );
		} elseif ( ! in_array( $decoded['failureClass'] ?? null, self::SOURCE_ERROR_FAILURE_CLASSES, true ) || ! is_string( $decoded['error'] ?? null ) ) {
			$result['status']       = TreeRenderer::STATUS_ERROR;
			$result['error']        = $label . ' source oracle returned malformed error details.';
			$result['failureClass'] = 'oracle-renderer-error';
			return $result;
		} else {
			$result['failureClass'] = $decoded['failureClass'];
			$result['error'] = $decoded['error'];
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
				'error'        => $metadata['versionError'] ?? $metadata['error'] ?? 'Chrome CDP oracle is unavailable.',
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
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}

		$decoded = $proc['decoded'] ?? null;
		if ( ! is_array( $decoded ) ) {
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Chrome CDP oracle did not return a valid JSON result.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}

		$status = $decoded['status'] ?? null;
		$valid_status = in_array( $status, array( TreeRenderer::STATUS_OK, TreeRenderer::STATUS_UNSUPPORTED, TreeRenderer::STATUS_ERROR ), true );
		$require_live = in_array( $status, array( TreeRenderer::STATUS_OK, TreeRenderer::STATUS_UNSUPPORTED ), true );
		$identity_error = is_array( $decoded['oracle'] ?? null )
			? $this->chrome_identity_error( $decoded['oracle'], $require_live )
			: 'did not return oracle metadata';
		if (
			! $valid_status ||
			null !== $identity_error ||
			! is_int( $decoded['nodeCount'] ?? null ) ||
			$decoded['nodeCount'] < 0 ||
			( TreeRenderer::STATUS_ERROR === $status && 0 !== $decoded['nodeCount'] )
		) {
			$reason = $identity_error ?? ( $valid_status ? 'invalid nodeCount' : 'invalid status' );
			return array(
				'status'       => TreeRenderer::STATUS_ERROR,
				'error'        => 'Chrome CDP oracle returned an invalid protocol result: ' . self::bounded_chrome_protocol_reason( $reason ) . '.',
				'failureClass' => 'oracle-renderer-error',
				'oracle'       => $metadata,
				'process'      => self::compact_process( $proc ),
			);
		}
		$include_live = $require_live || self::has_live_chrome_identity( $decoded['oracle'] );
		$result = array(
			'status'    => $status,
			'oracle'    => $this->trusted_chrome_metadata( $decoded['oracle'], $include_live ),
			'nodeCount' => $decoded['nodeCount'],
			'process'   => self::compact_process( $proc ),
		);
		if ( TreeRenderer::STATUS_OK === $status ) {
			if ( ! is_string( $decoded['treeBase64'] ?? null ) ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = 'Chrome CDP oracle returned ok without treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$tree = base64_decode( $decoded['treeBase64'], true );
			if ( false === $tree ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = 'Chrome CDP oracle returned invalid treeBase64.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			if ( array_key_exists( 'tree', $decoded ) && ( ! is_string( $decoded['tree'] ) || $decoded['tree'] !== $tree ) ) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = 'Chrome CDP oracle returned disagreeing tree and treeBase64 values.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['tree'] = $tree;
		} elseif ( TreeRenderer::STATUS_UNSUPPORTED === $status ) {
			if (
				'oracle-unsupported' !== ( $decoded['failureClass'] ?? null ) ||
				! is_string( $decoded['unsupported']['message'] ?? null )
			) {
				$result['status']       = TreeRenderer::STATUS_ERROR;
				$result['error']        = 'Chrome CDP oracle returned malformed unsupported details.';
				$result['failureClass'] = 'oracle-renderer-error';
				return $result;
			}
			$result['failureClass'] = 'oracle-unsupported';
			$result['unsupported'] = array( 'message' => $decoded['unsupported']['message'] );
		} elseif ( ! in_array( $decoded['failureClass'] ?? null, self::CHROME_ERROR_FAILURE_CLASSES, true ) || ! is_string( $decoded['error'] ?? null ) ) {
			$result['status']       = TreeRenderer::STATUS_ERROR;
			$result['error']        = 'Chrome CDP oracle returned malformed error details.';
			$result['failureClass'] = 'oracle-renderer-error';
		} else {
			$result['failureClass'] = $decoded['failureClass'];
			$result['error'] = $decoded['error'];
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
		$deadline = $start + ( $request_timeout_ms / 1000 );
		if ( false === $encoded || ! self::write_all_until( $server['pipes'][0], $encoded . "\n", $deadline ) ) {
			$command = $server['command'] ?? '';
			$timed_out = microtime( true ) >= $deadline;
			$cleanup_error = self::stop_chrome_server( $key );
			if ( $may_retry && null === $cleanup_error ) {
				return $this->chrome_request( $request, false );
			}
			$stderr = 'Could not write to Chrome CDP oracle server.';
			if ( null !== $cleanup_error ) {
				$stderr .= ' Cleanup failed: ' . $cleanup_error;
			}
			return array(
				'command'    => $command,
				'code'       => null,
				'timedOut'   => $timed_out,
				'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'stdout'     => '',
				'stderr'     => $stderr,
				'output'     => $stderr,
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
					self::capture_verified_chrome_server_pid( $server, $decoded );
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
				$cleanup_error = self::stop_chrome_server( $key, $status );
				if ( $may_retry && null === $cleanup_error ) {
					return $this->chrome_request( $request, false );
				}
				if ( null !== $cleanup_error ) {
					$stderr = implode( "\n", array_filter( array( trim( $stderr ), 'Cleanup failed: ' . $cleanup_error ), 'strlen' ) );
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
			if ( microtime( true ) >= $deadline ) {
				$stderr = $server['stderr'];
				$command = $server['command'];
				$cleanup_error = self::stop_chrome_server( $key );
				if ( null !== $cleanup_error ) {
					$stderr = implode( "\n", array_filter( array( trim( $stderr ), 'Cleanup failed: ' . $cleanup_error ), 'strlen' ) );
				}
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
		$deadline = $start + ( $timeout_ms / 1000 );
		$socket_uri = 0 === strpos( (string) $this->chrome_socket, 'unix://' )
			? $this->chrome_socket
			: 'unix://' . $this->chrome_socket;
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client( $socket_uri, $errno, $error, max( 0.001, $timeout_ms / 1000 ) );
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

		if ( false === $encoded || ! self::write_all_until( $socket, $encoded . "\n", $deadline ) ) {
			$timed_out = microtime( true ) >= $deadline;
			fclose( $socket );
			return array(
				'command'    => 'chrome-cdp socket ' . $this->chrome_socket,
				'code'       => null,
				'timedOut'   => $timed_out,
				'durationMs' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'stdout'     => '',
				'stderr'     => 'Could not write to Chrome CDP socket.',
				'output'     => 'Could not write to Chrome CDP socket.',
				'decoded'    => null,
			);
		}
		fflush( $socket );
		$remaining_microseconds = max( 1, (int) floor( ( $deadline - microtime( true ) ) * 1000000 ) );
		stream_set_timeout( $socket, intdiv( $remaining_microseconds, 1000000 ), $remaining_microseconds % 1000000 );

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

	private static function create_chrome_lifecycle_owner( string $script ): array {
		for ( $attempt = 0; $attempt < 100; ++$attempt ) {
			$token = bin2hex( random_bytes( 16 ) );
			$root = sys_get_temp_dir() . '/' . self::CHROME_LIFECYCLE_OWNER_PREFIX . $token;
			if ( ! @mkdir( $root, 0700 ) ) {
				continue;
			}
			$owner_path = $root . '/' . self::CHROME_LIFECYCLE_OWNER_FILE;
			if ( strlen( $token ) + 1 !== @file_put_contents( $owner_path, $token . "\n", LOCK_EX ) ) {
				@unlink( $owner_path );
				@rmdir( $root );
				throw new \RuntimeException( 'Could not write Chrome lifecycle ownership marker.' );
			}
			return array(
				'root'       => $root,
				'token'      => $token,
				'ownerPath'  => $owner_path,
				'recordPath' => $root . '/' . self::CHROME_LIFECYCLE_RECORD_FILE,
				'tempPath'   => $root . '/' . self::CHROME_LIFECYCLE_TEMP_FILE,
				'script'     => $script,
			);
		}
		throw new \RuntimeException( 'Could not reserve a unique Chrome lifecycle ownership root.' );
	}

	private static function restore_environment( string $name, $value ): void {
		putenv( false === $value ? $name : $name . '=' . $value );
	}

	private static function proc_open_with_chrome_lifecycle( array $command, array $spec, &$pipes, array $lifecycle ) {
		$old_root = getenv( 'HTML_API_FUZZ_CHROME_LIFECYCLE_ROOT' );
		$old_token = getenv( 'HTML_API_FUZZ_CHROME_LIFECYCLE_TOKEN' );
		putenv( 'HTML_API_FUZZ_CHROME_LIFECYCLE_ROOT=' . $lifecycle['root'] );
		putenv( 'HTML_API_FUZZ_CHROME_LIFECYCLE_TOKEN=' . $lifecycle['token'] );
		try {
			return proc_open( $command, $spec, $pipes, repo_root() );
		} finally {
			self::restore_environment( 'HTML_API_FUZZ_CHROME_LIFECYCLE_ROOT', $old_root );
			self::restore_environment( 'HTML_API_FUZZ_CHROME_LIFECYCLE_TOKEN', $old_token );
		}
	}

	private static function chrome_lifecycle_owner_error( array $lifecycle ): ?string {
		if ( ! is_dir( $lifecycle['root'] ) ) {
			return 'Chrome lifecycle ownership root is missing';
		}
		$owner = @file_get_contents( $lifecycle['ownerPath'] );
		if ( ! is_string( $owner ) || trim( $owner ) !== $lifecycle['token'] ) {
			return 'Chrome lifecycle ownership token does not match';
		}
		return null;
	}

	private static function read_chrome_lifecycle_record( array $lifecycle, string $path ): array {
		$text = @file_get_contents( $path );
		if ( ! is_string( $text ) ) {
			return array( 'record' => null, 'error' => 'Chrome lifecycle record could not be read' );
		}
		$record = json_decode( $text, true );
		if ( ! is_array( $record ) ) {
			return array( 'record' => null, 'error' => 'Chrome lifecycle record is not valid JSON' );
		}
		$profile_path = $record['profilePath'] ?? null;
		$phase = $record['phase'] ?? null;
		$group_pid = $record['browserGroupPid'] ?? null;
		if (
			1 !== ( $record['schemaVersion'] ?? null ) ||
			$lifecycle['token'] !== ( $record['token'] ?? null ) ||
			! is_int( $record['serverPid'] ?? null ) || $record['serverPid'] < 1 ||
			! in_array( $phase, array( 'intent', 'gated' ), true ) ||
			! is_string( $profile_path ) || dirname( $profile_path ) !== $lifecycle['root'] ||
			1 !== preg_match( '/^profile-[A-Za-z0-9]{6}$/', basename( $profile_path ) ) ||
			( 'intent' === $phase && null !== $group_pid ) ||
			( 'gated' === $phase && ( ! is_int( $group_pid ) || $group_pid < 1 ) )
		) {
			return array( 'record' => null, 'error' => 'Chrome lifecycle record failed ownership validation' );
		}
		return array( 'record' => $record, 'error' => null );
	}

	private static function chrome_lifecycle_node_pid( array $lifecycle ): ?int {
		if ( null !== self::chrome_lifecycle_owner_error( $lifecycle ) ) {
			return null;
		}
		foreach ( array( $lifecycle['recordPath'], $lifecycle['tempPath'] ) as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$decoded = self::read_chrome_lifecycle_record( $lifecycle, $path );
			if ( is_array( $decoded['record'] ) ) {
				return $decoded['record']['serverPid'];
			}
		}
		return null;
	}

	private static function capture_verified_chrome_server_pid( array &$server, array $decoded ): void {
		$server_pid = $decoded['serverPid'] ?? null;
		if ( ! is_int( $server_pid ) || $server_pid < 1 ) {
			return;
		}
		$record_pid = self::chrome_lifecycle_node_pid( $server['lifecycle'] );
		if ( $server_pid === $record_pid ) {
			$server['daemonPid'] = $server_pid;
		}
	}

	private static function chrome_process_group_state( int $group_pid ): array {
		if ( ! function_exists( 'posix_kill' ) || ! function_exists( 'posix_get_last_error' ) ) {
			return array( 'alive' => null, 'error' => 'POSIX process-group inspection is unavailable' );
		}
		if ( function_exists( 'posix_clear_last_error' ) ) {
			posix_clear_last_error();
		}
		if ( @posix_kill( -$group_pid, 0 ) ) {
			return array( 'alive' => true, 'error' => null );
		}
		$error = posix_get_last_error();
		$esrch = defined( 'PCNTL_ESRCH' ) ? constant( 'PCNTL_ESRCH' ) : 3;
		if ( $esrch === $error ) {
			return array( 'alive' => false, 'error' => null );
		}
		return array( 'alive' => null, 'error' => 'Could not inspect Chrome process group ' . $group_pid . ': ' . posix_strerror( $error ) );
	}

	private static function wait_for_chrome_process_group( int $group_pid, float $seconds ): array {
		$deadline = microtime( true ) + $seconds;
		do {
			$state = self::chrome_process_group_state( $group_pid );
			if ( false === $state['alive'] || null !== $state['error'] ) {
				return $state;
			}
			usleep( 25000 );
		} while ( microtime( true ) < $deadline );
		return self::chrome_process_group_state( $group_pid );
	}

	private static function bounded_ps_command( int $pid ): ?string {
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( array( '/bin/ps', '-ww', '-p', (string) $pid, '-o', 'command=' ), $spec, $pipes );
		if ( ! is_resource( $process ) ) {
			return null;
		}
		@fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$output = '';
		$deadline = microtime( true ) + 1.0;
		do {
			$output = substr( $output . stream_get_contents( $pipes[1] ), -65536 );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				break;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		$status = proc_get_status( $process );
		if ( $status['running'] ) {
			@proc_terminate( $process, 9 );
		}
		$output = substr( $output . stream_get_contents( $pipes[1] ), -65536 );
		@fclose( $pipes[1] );
		@fclose( $pipes[2] );
		@proc_close( $process );
		return trim( $output );
	}

	private static function chrome_supervisor_identity_error( int $pid, array $lifecycle, array $record ): ?string {
		$profile = $record['profilePath'];
		if ( 'Linux' === PHP_OS_FAMILY ) {
			$command = @file_get_contents( '/proc/' . $pid . '/cmdline' );
			if ( ! is_string( $command ) ) {
				return 'Chrome supervisor group leader is missing';
			}
			$arguments = array_values( array_filter( explode( "\0", $command ), 'strlen' ) );
			$profile_index = array_search( '--profile', $arguments, true );
			if (
				! in_array( $lifecycle['script'], $arguments, true ) ||
				! in_array( '--chrome-supervisor', $arguments, true ) ||
				false === $profile_index || ( $arguments[ $profile_index + 1 ] ?? null ) !== $profile
			) {
				return 'Chrome supervisor group leader identity does not match';
			}
			return null;
		}
		if ( 'Darwin' === PHP_OS_FAMILY ) {
			$command = self::bounded_ps_command( $pid );
			if (
				! is_string( $command ) || '' === $command ||
				false === strpos( $command, $lifecycle['script'] ) ||
				false === strpos( $command, '--chrome-supervisor' ) ||
				false === strpos( $command, $profile )
			) {
				return 'Chrome supervisor group leader identity does not match';
			}
			return null;
		}
		return 'Chrome supervisor identity validation is unsupported on ' . PHP_OS_FAMILY;
	}

	private static function remove_chrome_lifecycle_root( array $lifecycle ): ?string {
		$deadline = microtime( true ) + 5.0;
		$stable_since = null;
		do {
			if ( ! file_exists( $lifecycle['root'] ) ) {
				$stable_since = $stable_since ?? microtime( true );
				if ( microtime( true ) - $stable_since >= 0.5 ) {
					return null;
				}
				usleep( 50000 );
				continue;
			}
			$stable_since = null;
			$owner_error = self::chrome_lifecycle_owner_error( $lifecycle );
			if ( null !== $owner_error ) {
				return $owner_error . '; ownership root retained';
			}
			$items = scandir( $lifecycle['root'] );
			if ( false === $items ) {
				return 'Could not inspect Chrome lifecycle ownership root; root retained';
			}
			foreach ( $items as $item ) {
				if ( in_array( $item, array( '.', '..', self::CHROME_LIFECYCLE_OWNER_FILE ), true ) ) {
					continue;
				}
				remove_dir_recursive( $lifecycle['root'] . '/' . $item );
			}
			@unlink( $lifecycle['ownerPath'] );
			@rmdir( $lifecycle['root'] );
			usleep( 50000 );
		} while ( microtime( true ) < $deadline );
		return 'Chrome lifecycle ownership root did not remain removed for 500ms';
	}

	private static function reconcile_chrome_lifecycle( array $lifecycle ): ?string {
		if ( ! file_exists( $lifecycle['root'] ) ) {
			return null;
		}
		$owner_error = self::chrome_lifecycle_owner_error( $lifecycle );
		if ( null !== $owner_error ) {
			return $owner_error . '; ownership root retained';
		}
		if ( file_exists( $lifecycle['tempPath'] ) ) {
			return 'Chrome lifecycle temporary record survived daemon exit; ownership root retained';
		}
		if ( ! file_exists( $lifecycle['recordPath'] ) ) {
			return self::remove_chrome_lifecycle_root( $lifecycle );
		}
		$decoded = self::read_chrome_lifecycle_record( $lifecycle, $lifecycle['recordPath'] );
		if ( null !== $decoded['error'] ) {
			return $decoded['error'] . '; ownership root retained';
		}
		$record = $decoded['record'];
		if ( 'gated' === $record['phase'] ) {
			$group_pid = $record['browserGroupPid'];
			$state = self::chrome_process_group_state( $group_pid );
			if ( null !== $state['error'] ) {
				return $state['error'] . '; ownership root retained';
			}
			if ( $state['alive'] ) {
				$identity_error = self::chrome_supervisor_identity_error( $group_pid, $lifecycle, $record );
				if ( null !== $identity_error ) {
					return $identity_error . '; ownership root retained';
				}
				@posix_kill( -$group_pid, 15 );
				$state = self::wait_for_chrome_process_group( $group_pid, 2.0 );
			}
			if ( null !== $state['error'] ) {
				return $state['error'] . '; ownership root retained';
			}
			if ( $state['alive'] ) {
				$identity_error = self::chrome_supervisor_identity_error( $group_pid, $lifecycle, $record );
				if ( null !== $identity_error ) {
					return $identity_error . '; ownership root retained';
				}
				@posix_kill( -$group_pid, 9 );
				$state = self::wait_for_chrome_process_group( $group_pid, 3.0 );
			}
			if ( null !== $state['error'] ) {
				return $state['error'] . '; ownership root retained';
			}
			if ( $state['alive'] ) {
				return 'Chrome supervisor process group survived SIGKILL; ownership root retained';
			}
		}
		return self::remove_chrome_lifecycle_root( $lifecycle );
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
		$lifecycle = self::create_chrome_lifecycle_owner( $this->chrome_oracle_script );
		try {
			$process = self::proc_open_with_chrome_lifecycle( $command, $spec, $pipes, $lifecycle );
		} catch ( \Throwable $error ) {
			$cleanup_error = self::remove_chrome_lifecycle_root( $lifecycle );
			$message = 'Could not start the persistent Chrome CDP run service: ' . $error->getMessage();
			if ( null !== $cleanup_error ) {
				$message .= '; cleanup failed: ' . $cleanup_error;
			}
			throw new \RuntimeException( $message, 0, $error );
		}
		if ( ! is_resource( $process ) ) {
			$cleanup_error = self::remove_chrome_lifecycle_root( $lifecycle );
			$message = 'Could not start the persistent Chrome CDP run service.';
			if ( null !== $cleanup_error ) {
				$message .= ' Cleanup failed: ' . $cleanup_error;
			}
			throw new \RuntimeException( $message );
		}
		$initial_status = proc_get_status( $process );
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$key = sha1( $socket_path );
		self::$chrome_run_services[ $key ] = array(
			'process'    => $process,
			'pipes'      => $pipes,
			'command'    => command_string( $command ),
			'socketPath' => $socket_path,
			'daemonPid'  => null,
			'wrapperPid' => is_int( $initial_status['pid'] ?? null ) ? $initial_status['pid'] : null,
			'lifecycle'  => $lifecycle,
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
				$cleanup_error = self::stop_chrome_run_service( $key );
				$this->run_service_key = null;
				$this->chrome_socket = null;
				$details = implode( "\n", array_filter( array( trim( $stderr ), (string) $cleanup_error ), 'strlen' ) );
				throw new \RuntimeException( 'Persistent Chrome CDP service did not become ready: ' . $details );
			}
			usleep( 10000 );
		}

		$probe = null;
		$probe_identity_error = null;
		do {
			$remaining_ms = (int) floor( ( $deadline - microtime( true ) ) * 1000 );
			if ( $remaining_ms < 1 ) {
				break;
			}
			$probe = $this->chrome_socket_request( array( 'command' => 'version' ), $remaining_ms );
			if ( is_array( $probe['decoded'] ?? null ) ) {
				self::capture_verified_chrome_server_pid( self::$chrome_run_services[ $key ], $probe['decoded'] );
			}
			$probe_identity_error = is_array( $probe['decoded']['oracle'] ?? null )
				? $this->chrome_identity_error( $probe['decoded']['oracle'], true )
				: 'did not return oracle metadata';
			if ( 'ok' === ( $probe['decoded']['status'] ?? null ) && null === $probe_identity_error ) {
				return;
			}
			if ( is_array( $probe['decoded'] ?? null ) ) {
				break;
			}
			$status = proc_get_status( $process );
			$remaining_microseconds = (int) floor( ( $deadline - microtime( true ) ) * 1000000 );
			if ( ! $status['running'] || $remaining_microseconds < 1 ) {
				break;
			}
			usleep( min( 10000, $remaining_microseconds ) );
		} while ( true );

		$stderr = trim( (string) stream_get_contents( $pipes[2] ) );
		$probe_output = trim( (string) ( $probe['output'] ?? '' ) );
		$identity_details = null === $probe_identity_error ? '' : 'Invalid Chrome CDP readiness identity: ' . $probe_identity_error . '.';
		$details = implode( "\n", array_filter( array( $identity_details, $probe_output, $stderr ), 'strlen' ) );
		$cleanup_error = self::stop_chrome_run_service( $key );
		$this->run_service_key = null;
		$this->chrome_socket = null;
		if ( null !== $cleanup_error ) {
			$details = implode( "\n", array_filter( array( $details, $cleanup_error ), 'strlen' ) );
		}
		throw new \RuntimeException( 'Persistent Chrome CDP service failed its readiness probe: ' . $details );
	}

	public function stop_run_service(): void {
		if ( null === $this->run_service_key ) {
			return;
		}
		$key = $this->run_service_key;
		$this->run_service_key = null;
		$this->chrome_socket = null;
		$cleanup_error = self::stop_chrome_run_service( $key );
		if ( null !== $cleanup_error ) {
			throw new \RuntimeException( 'Persistent Chrome CDP service cleanup failed: ' . $cleanup_error );
		}
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
		$lifecycle = self::create_chrome_lifecycle_owner( $this->chrome_oracle_script );
		try {
			$process = self::proc_open_with_chrome_lifecycle( $command, $spec, $pipes, $lifecycle );
		} catch ( \Throwable $error ) {
			$cleanup_error = self::remove_chrome_lifecycle_root( $lifecycle );
			$message = 'Could not start Chrome CDP oracle server: ' . $error->getMessage();
			if ( null !== $cleanup_error ) {
				$message .= '; cleanup failed: ' . $cleanup_error;
			}
			throw new \RuntimeException( $message, 0, $error );
		}
		if ( ! is_resource( $process ) ) {
			$cleanup_error = self::remove_chrome_lifecycle_root( $lifecycle );
			$message = 'Could not start Chrome CDP oracle server.';
			if ( null !== $cleanup_error ) {
				$message .= ' Cleanup failed: ' . $cleanup_error;
			}
			throw new \RuntimeException( $message );
		}
		$initial_status = proc_get_status( $process );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		self::$chrome_servers[ $key ] = array(
			'process' => $process,
			'pipes'   => $pipes,
			'command' => command_string( $command ),
			'stdout'  => '',
			'stderr'  => '',
			'daemonPid' => null,
			'wrapperPid' => is_int( $initial_status['pid'] ?? null ) ? $initial_status['pid'] : null,
			'lifecycle' => $lifecycle,
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
			$cleanup_error = self::stop_chrome_server( $key );
			if ( null !== $cleanup_error ) {
				fwrite( STDERR, "Persistent Chrome CDP stdio service cleanup failed during PHP shutdown: {$cleanup_error}\n" );
			}
		}
		foreach ( array_keys( self::$chrome_run_services ) as $key ) {
			$cleanup_error = self::stop_chrome_run_service( $key );
			if ( null !== $cleanup_error ) {
				fwrite( STDERR, "Persistent Chrome CDP service cleanup failed during PHP shutdown: {$cleanup_error}\n" );
			}
		}
	}

	private static function stop_chrome_server( string $key, ?array $observed_status = null ): ?string {
		if ( ! isset( self::$chrome_servers[ $key ] ) ) {
			return null;
		}
		$server = self::$chrome_servers[ $key ];
		unset( self::$chrome_servers[ $key ] );
		if ( isset( $server['pipes'][0] ) && is_resource( $server['pipes'][0] ) ) {
			self::write_all_until( $server['pipes'][0], "{\"command\":\"shutdown\"}\n", microtime( true ) + 0.5 );
			@fclose( $server['pipes'][0] );
		}
		$verified_pid = self::chrome_lifecycle_node_pid( $server['lifecycle'] ) ?? ( $server['daemonPid'] ?? null );
		$outcome = self::await_or_terminate( $server['process'], $verified_pid, $observed_status );
		$stderr = trim( (string) ( $server['stderr'] ?? '' ) );
		foreach ( array( 1, 2 ) as $pipe_index ) {
			if ( isset( $server['pipes'][ $pipe_index ] ) && is_resource( $server['pipes'][ $pipe_index ] ) ) {
				if ( 2 === $pipe_index ) {
					$stderr = implode( "\n", array_filter( array( $stderr, trim( (string) stream_get_contents( $server['pipes'][ $pipe_index ] ) ) ), 'strlen' ) );
				}
				@fclose( $server['pipes'][ $pipe_index ] );
			}
		}
		$close_code = null;
		if ( ! $outcome['stillRunning'] ) {
			$closed = @proc_close( $server['process'] );
			$close_code = is_int( $closed ) && $closed >= 0 ? $closed : null;
		}
		$exit_code = is_int( $outcome['exitCode'] ) && $outcome['exitCode'] >= 0 ? $outcome['exitCode'] : $close_code;
		$failures = array();
		if ( $outcome['stillRunning'] ) {
			$failures[] = 'daemon survived SIGKILL';
		} elseif ( null !== $outcome['forced'] ) {
			$failures[] = 'graceful shutdown failed; required ' . $outcome['forced'];
		}
		if ( null !== $exit_code && 0 !== $exit_code ) {
			$failures[] = "daemon exited with code {$exit_code}";
		}
		$stderr = trim( $stderr );
		if ( '' !== $stderr ) {
			$failures[] = 'daemon stderr: ' . $stderr;
		}
		if ( $outcome['stillRunning'] ) {
			$failures[] = 'Chrome lifecycle ownership root retained while daemon is still running';
		} else {
			$lifecycle_error = self::reconcile_chrome_lifecycle( $server['lifecycle'] );
			if ( null !== $lifecycle_error ) {
				$failures[] = $lifecycle_error;
			}
		}
		return empty( $failures ) ? null : implode( '; ', $failures );
	}

	private static function stop_chrome_run_service( string $key ): ?string {
		if ( ! isset( self::$chrome_run_services[ $key ] ) ) {
			return null;
		}
		$service = self::$chrome_run_services[ $key ];
		unset( self::$chrome_run_services[ $key ] );
		$socket_path = $service['socketPath'];
		$acknowledged = false;
		$shutdown_requested = false;
		$socket = @stream_socket_client( 'unix://' . $socket_path, $errno, $error, 0.5 );
		if ( is_resource( $socket ) ) {
			if ( self::write_all_until( $socket, "{\"id\":0,\"command\":\"shutdown\"}\n", microtime( true ) + 0.5 ) ) {
				$shutdown_requested = true;
				@fflush( $socket );
				$deadline = microtime( true ) + 2.0;
				do {
					$remaining_microseconds = max( 1, (int) floor( ( $deadline - microtime( true ) ) * 1000000 ) );
					@stream_set_timeout( $socket, intdiv( $remaining_microseconds, 1000000 ), $remaining_microseconds % 1000000 );
					$line = @fgets( $socket );
					if ( false === $line ) {
						break;
					}
					$acknowledgement = json_decode( trim( $line ), true );
					if ( is_array( $acknowledgement ) && 0 === ( $acknowledgement['id'] ?? null ) ) {
						self::capture_verified_chrome_server_pid( $service, $acknowledgement );
					}
					if (
						is_array( $acknowledgement ) &&
						0 === ( $acknowledgement['id'] ?? null ) &&
						'ok' === ( $acknowledgement['status'] ?? null ) &&
						true === ( $acknowledgement['shutdown'] ?? null )
					) {
						$acknowledged = true;
						break;
					}
				} while ( microtime( true ) < $deadline );
			}
			@fclose( $socket );
		}
		$verified_pid = self::chrome_lifecycle_node_pid( $service['lifecycle'] ) ?? ( $service['daemonPid'] ?? null );
		$outcome = self::await_or_terminate( $service['process'], $verified_pid );
		$stderr = '';
		foreach ( array( 1, 2 ) as $pipe_index ) {
			if ( isset( $service['pipes'][ $pipe_index ] ) && is_resource( $service['pipes'][ $pipe_index ] ) ) {
				if ( 2 === $pipe_index ) {
					$stderr = trim( (string) stream_get_contents( $service['pipes'][ $pipe_index ] ) );
				}
				@fclose( $service['pipes'][ $pipe_index ] );
			}
		}
		$close_code = null;
		if ( ! $outcome['stillRunning'] ) {
			$closed = @proc_close( $service['process'] );
			$close_code = is_int( $closed ) && $closed >= 0 ? $closed : null;
		}
		@unlink( $socket_path );

		$exit_code = is_int( $outcome['exitCode'] ) && $outcome['exitCode'] >= 0 ? $outcome['exitCode'] : $close_code;
		$failures = array();
		if ( $outcome['stillRunning'] ) {
			$failures[] = 'daemon survived SIGKILL';
		} elseif ( null !== $outcome['forced'] ) {
			$failures[] = 'graceful shutdown failed; required ' . $outcome['forced'];
		}
		if ( null !== $exit_code && 0 !== $exit_code ) {
			$failures[] = "daemon exited with code {$exit_code}";
		}
		if ( '' !== $stderr ) {
			$failures[] = 'daemon stderr: ' . $stderr;
		}
		if ( $shutdown_requested && ! $acknowledged ) {
			$failures[] = 'daemon did not acknowledge shutdown';
		}
		if ( $outcome['stillRunning'] ) {
			$failures[] = 'Chrome lifecycle ownership root retained while daemon is still running';
		} else {
			$lifecycle_error = self::reconcile_chrome_lifecycle( $service['lifecycle'] );
			if ( null !== $lifecycle_error ) {
				$failures[] = $lifecycle_error;
			}
		}
		return empty( $failures ) ? null : implode( '; ', $failures );
	}

	private static function await_or_terminate( $process, ?int $daemon_pid = null, ?array $observed_status = null ): array {
		$forced = null;
		if ( is_array( $observed_status ) && false === ( $observed_status['running'] ?? true ) ) {
			return array( 'exitCode' => $observed_status['exitcode'] ?? null, 'forced' => null, 'stillRunning' => false );
		}
		$deadline = microtime( true ) + 30.0;
		do {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				return array( 'exitCode' => $status['exitcode'], 'forced' => $forced, 'stillRunning' => false );
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		if ( null !== $daemon_pid && $daemon_pid > 0 && function_exists( 'posix_kill' ) ) {
			@posix_kill( $daemon_pid, 15 );
		} else {
			@proc_terminate( $process );
		}
		$forced = 'SIGTERM';
		$deadline = microtime( true ) + 15.0;
		do {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				return array( 'exitCode' => $status['exitcode'], 'forced' => $forced, 'stillRunning' => false );
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		if ( null !== $daemon_pid && $daemon_pid > 0 && function_exists( 'posix_kill' ) ) {
			@posix_kill( $daemon_pid, 9 );
		} else {
			@proc_terminate( $process, 9 );
		}
		$forced = 'SIGKILL';
		$deadline = microtime( true ) + 3.0;
		do {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				return array( 'exitCode' => $status['exitcode'], 'forced' => $forced, 'stillRunning' => false );
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		$status = proc_get_status( $process );
		return array( 'exitCode' => $status['exitcode'], 'forced' => $forced, 'stillRunning' => (bool) $status['running'] );
	}

	private static function write_all_until( $stream, string $bytes, float $deadline ): bool {
		$length = strlen( $bytes );
		$offset = 0;
		$was_blocking = (bool) ( stream_get_meta_data( $stream )['blocked'] ?? true );
		if ( ! @stream_set_blocking( $stream, false ) ) {
			return false;
		}
		try {
			while ( $offset < $length ) {
				$remaining = $deadline - microtime( true );
				if ( $remaining <= 0 ) {
					return false;
				}
				$seconds = (int) floor( $remaining );
				$microseconds = min( 999999, max( 0, (int) floor( ( $remaining - $seconds ) * 1000000 ) ) );
				$read = array();
				$write = array( $stream );
				$except = array();
				$selected = @stream_select( $read, $write, $except, $seconds, $microseconds );
				if ( false === $selected || 0 === $selected ) {
					return false;
				}
				$written = @fwrite( $stream, substr( $bytes, $offset ) );
				if ( false === $written ) {
					return false;
				}
				$offset += $written;
			}
			return true;
		} finally {
			if ( $was_blocking ) {
				@stream_set_blocking( $stream, true );
			}
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
