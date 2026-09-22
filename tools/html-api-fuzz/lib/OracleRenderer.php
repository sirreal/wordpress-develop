<?php
namespace HtmlApiFuzz;

/** Parse one JSON value while rejecting duplicate object keys and trailing bytes. */
final class StrictJsonParser {
	private string $input;
	private int $length;
	private int $offset = 0;
	private int $maximum_depth;

	private function __construct( string $input, int $maximum_depth ) {
		$this->input         = $input;
		$this->length        = strlen( $input );
		$this->maximum_depth = $maximum_depth;
	}

	public static function decode( string $input, int $maximum_depth = 64 ) {
		if ( 1 !== preg_match( '//u', $input ) ) {
			throw new \RuntimeException( 'JSON is not valid UTF-8.' );
		}
		$parser = new self( $input, $maximum_depth );
		$value  = $parser->parse_value( 0 );
		$parser->skip_whitespace();
		if ( $parser->offset !== $parser->length ) {
			throw new \RuntimeException( 'JSON contains trailing values or bytes.' );
		}
		return $value;
	}

	private function parse_value( int $depth ) {
		if ( $depth > $this->maximum_depth ) {
			throw new \RuntimeException( 'JSON exceeds its nesting-depth limit.' );
		}
		$this->skip_whitespace();
		if ( $this->offset >= $this->length ) {
			throw new \RuntimeException( 'JSON ended before a value.' );
		}
		$byte = $this->input[ $this->offset ];
		if ( '{' === $byte ) {
			return $this->parse_object( $depth + 1 );
		}
		if ( '[' === $byte ) {
			return $this->parse_array( $depth + 1 );
		}
		if ( '"' === $byte ) {
			return $this->parse_string();
		}
		foreach ( array( 'true' => true, 'false' => false, 'null' => null ) as $literal => $value ) {
			if ( substr_compare( $this->input, $literal, $this->offset, strlen( $literal ) ) === 0 ) {
				$this->offset += strlen( $literal );
				return $value;
			}
		}
		if ( '-' === $byte || ( $byte >= '0' && $byte <= '9' ) ) {
			return $this->parse_number();
		}
		throw new \RuntimeException( 'JSON contains an invalid value.' );
	}

	private function parse_object( int $depth ): array {
		++$this->offset;
		$this->skip_whitespace();
		$result = array();
		$seen   = array();
		if ( $this->consume( '}' ) ) {
			return $result;
		}
		while ( true ) {
			$this->skip_whitespace();
			if ( $this->offset >= $this->length || '"' !== $this->input[ $this->offset ] ) {
				throw new \RuntimeException( 'JSON object key is not a string.' );
			}
			$key = $this->parse_string();
			$seen_key = "key\0" . $key;
			if ( isset( $seen[ $seen_key ] ) ) {
				throw new \RuntimeException( 'JSON contains a duplicate object key.' );
			}
			$seen[ $seen_key ] = true;
			$this->skip_whitespace();
			if ( ! $this->consume( ':' ) ) {
				throw new \RuntimeException( 'JSON object key is missing its colon.' );
			}
			$result[ $key ] = $this->parse_value( $depth );
			$this->skip_whitespace();
			if ( $this->consume( '}' ) ) {
				return $result;
			}
			if ( ! $this->consume( ',' ) ) {
				throw new \RuntimeException( 'JSON object is missing a comma.' );
			}
		}
	}

	private function parse_array( int $depth ): array {
		++$this->offset;
		$this->skip_whitespace();
		$result = array();
		if ( $this->consume( ']' ) ) {
			return $result;
		}
		while ( true ) {
			$result[] = $this->parse_value( $depth );
			$this->skip_whitespace();
			if ( $this->consume( ']' ) ) {
				return $result;
			}
			if ( ! $this->consume( ',' ) ) {
				throw new \RuntimeException( 'JSON array is missing a comma.' );
			}
		}
	}

	private function parse_string(): string {
		$start = $this->offset++;
		while ( $this->offset < $this->length ) {
			$byte = ord( $this->input[ $this->offset ] );
			if ( 0x22 === $byte ) {
				++$this->offset;
				$raw = substr( $this->input, $start, $this->offset - $start );
				try {
					$value = json_decode( $raw, true, 2, JSON_THROW_ON_ERROR );
				} catch ( \JsonException $error ) {
					throw new \RuntimeException( 'JSON contains a malformed string escape.', 0, $error );
				}
				if ( ! is_string( $value ) ) {
					throw new \RuntimeException( 'JSON string could not be decoded.' );
				}
				return $value;
			}
			if ( $byte < 0x20 ) {
				throw new \RuntimeException( 'JSON string contains an unescaped control byte.' );
			}
			if ( 0x5c === $byte ) {
				++$this->offset;
				if ( $this->offset >= $this->length ) {
					break;
				}
				$escape = $this->input[ $this->offset ];
				if ( 'u' === $escape ) {
					if ( $this->offset + 4 >= $this->length || 1 !== preg_match( '/^[0-9a-fA-F]{4}$/D', substr( $this->input, $this->offset + 1, 4 ) ) ) {
						throw new \RuntimeException( 'JSON contains a malformed Unicode escape.' );
					}
					$this->offset += 4;
				} elseif ( false === strpos( '"\\/bfnrt', $escape ) ) {
					throw new \RuntimeException( 'JSON contains an unknown string escape.' );
				}
			}
			++$this->offset;
		}
		throw new \RuntimeException( 'JSON string is unterminated.' );
	}

	private function parse_number() {
		$remaining = substr( $this->input, $this->offset );
		if ( 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $remaining, $matches ) ) {
			throw new \RuntimeException( 'JSON contains a malformed number.' );
		}
		$token = $matches[0];
		$this->offset += strlen( $token );
		try {
			$value = json_decode( $token, true, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $error ) {
			throw new \RuntimeException( 'JSON number could not be decoded.', 0, $error );
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			throw new \RuntimeException( 'JSON number is not finite.' );
		}
		return $value;
	}

	private function skip_whitespace(): void {
		while ( $this->offset < $this->length && false !== strpos( " \t\r\n", $this->input[ $this->offset ] ) ) {
			++$this->offset;
		}
	}

	private function consume( string $byte ): bool {
		if ( $this->offset < $this->length && $byte === $this->input[ $this->offset ] ) {
			++$this->offset;
			return true;
		}
		return false;
	}
}

class OracleRenderer {
	public const KIND_PHP_DOM          = 'php-dom';
	public const KIND_LEXBOR_SOURCE    = 'lexbor-source';
	public const KIND_HTML5EVER_SOURCE = 'html5ever-source';
	public const KIND_CHROME_CDP       = 'chrome-cdp';

	private const METADATA_SCHEMA_VERSION = 1;
	private const DEFAULT_TIMEOUT_MS = 2500;
	private const SOURCE_STDOUT_MAX_BYTES = 67108864;
	private const SOURCE_STDERR_MAX_BYTES = 1048576;
	private const CONTROL_MAX_BYTES = 65536;
	private const MANIFEST_MAX_BYTES = 1048576;
	private const CLEANUP_GRACE_MS = 5000;

	private string $kind;
	private ?string $source_binary;
	private int $timeout_ms;
	private ?ChromeOracleRenderer $chrome_renderer;
	private ?array $metadata = null;
	private ?string $source_manifest_sha256 = null;

	private function __construct( string $kind, ?string $source_binary = null, int $timeout_ms = self::DEFAULT_TIMEOUT_MS, ?ChromeOracleRenderer $chrome_renderer = null ) {
		$this->kind          = $kind;
		$this->source_binary = $source_binary;
		$this->timeout_ms    = $timeout_ms;
		$this->chrome_renderer = $chrome_renderer;
	}

	public static function from_options( array $options ): self {
		foreach ( array( 'dom-oracle', 'lexbor-oracle-bin', 'html5ever-oracle-bin', 'chrome-oracle-script', 'chrome-executable', 'node-bin', 'oracle-timeout-ms', 'chrome-startup-timeout-ms' ) as $value_option ) {
			if ( array_key_exists( $value_option, $options ) && true === $options[ $value_option ] ) {
				throw new \InvalidArgumentException( "Expected --{$value_option} to have a value." );
			}
		}
		$kind = option_string( $options, 'dom-oracle', self::KIND_PHP_DOM );
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			throw new \InvalidArgumentException( 'Expected --dom-oracle to be php-dom, lexbor-source, html5ever-source, or chrome-cdp.' );
		}

		$source_binary = null;
		if ( self::KIND_LEXBOR_SOURCE === $kind ) {
			$source_binary = option_string( $options, 'lexbor-oracle-bin', getenv( 'HTML_API_FUZZ_LEXBOR_ORACLE' ) ?: null );
			if ( null === $source_binary || '' === $source_binary ) {
				$source_binary = repo_root() . '/tools/html-api-fuzz/oracles/lexbor/build/lexbor-tree-oracle';
			}
		} elseif ( self::KIND_HTML5EVER_SOURCE === $kind ) {
			$source_binary = option_string( $options, 'html5ever-oracle-bin', getenv( 'HTML_API_FUZZ_HTML5EVER_ORACLE' ) ?: null );
			if ( null === $source_binary || '' === $source_binary ) {
				$source_binary = repo_root() . '/tools/html-api-fuzz/oracles/html5ever/build/html5ever-tree-oracle';
			}
		}

		$timeout_ms = option_int( $options, 'oracle-timeout-ms', self::DEFAULT_TIMEOUT_MS );
		if ( $timeout_ms < 1 ) {
			throw new \InvalidArgumentException( 'Expected --oracle-timeout-ms to be positive.' );
		}

		$chrome_renderer = null;
		if ( self::KIND_CHROME_CDP === $kind ) {
			$script = option_string( $options, 'chrome-oracle-script', getenv( 'HTML_API_FUZZ_CHROME_ORACLE' ) ?: null );
			if ( array_key_exists( 'chrome-oracle-script', $options ) && '' === $script ) {
				throw new \InvalidArgumentException( 'Expected --chrome-oracle-script to be non-empty.' );
			}
			if ( null === $script || '' === $script ) {
				$script = repo_root() . '/tools/html-api-fuzz/oracles/chrome/chrome-tree-oracle.js';
			}
			$chrome_executable = option_string( $options, 'chrome-executable', getenv( 'HTML_API_FUZZ_CHROME_EXECUTABLE' ) ?: null );
			if ( array_key_exists( 'chrome-executable', $options ) && '' === $chrome_executable ) {
				throw new \InvalidArgumentException( 'Expected --chrome-executable to be non-empty.' );
			}
			$node_binary = option_string( $options, 'node-bin', getenv( 'HTML_API_FUZZ_NODE_BIN' ) ?: 'node' );
			if ( null === $node_binary || '' === $node_binary ) {
				throw new \InvalidArgumentException( 'Expected --node-bin to be non-empty.' );
			}
			$startup_timeout_ms = self::chrome_startup_timeout_from_options( $options );
			$chrome_renderer = new ChromeOracleRenderer( $script, $chrome_executable, $node_binary, $timeout_ms, $startup_timeout_ms );
		}

		return new self( $kind, $source_binary, $timeout_ms, $chrome_renderer );
	}

	public static function kinds(): array {
		return array( self::KIND_PHP_DOM, self::KIND_LEXBOR_SOURCE, self::KIND_HTML5EVER_SOURCE, self::KIND_CHROME_CDP );
	}

	private static function chrome_startup_timeout_from_options( array $options ): int {
		if ( array_key_exists( 'chrome-startup-timeout-ms', $options ) ) {
			$value = option_int( $options, 'chrome-startup-timeout-ms', ChromeOracleRenderer::DEFAULT_STARTUP_TIMEOUT_MS );
		} else {
			$environment = getenv( 'HTML_API_FUZZ_CHROME_STARTUP_TIMEOUT_MS' );
			if ( false === $environment || '' === $environment ) {
				$value = ChromeOracleRenderer::DEFAULT_STARTUP_TIMEOUT_MS;
			} else {
				$parsed = filter_var( $environment, FILTER_VALIDATE_INT );
				if ( false === $parsed ) {
					throw new \InvalidArgumentException( 'Expected HTML_API_FUZZ_CHROME_STARTUP_TIMEOUT_MS to be an integer.' );
				}
				$value = (int) $parsed;
			}
		}
		if ( $value < 1 ) {
			throw new \InvalidArgumentException( 'Expected --chrome-startup-timeout-ms to be positive.' );
		}
		return $value;
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
			$available = class_exists( 'Dom\\HTMLDocument' );
			$this->metadata = array(
				'schemaVersion' => self::METADATA_SCHEMA_VERSION,
				'kind'          => self::KIND_PHP_DOM,
				'available'     => $available,
				'identity'      => $available ? array(
					'schemaVersion'   => 1,
					'kind'            => self::KIND_PHP_DOM,
					'phpVersion'      => PHP_VERSION,
					'phpVersionId'    => PHP_VERSION_ID,
					'phpSapi'         => PHP_SAPI,
					'zendVersion'     => zend_version(),
					'libxmlVersion'   => defined( 'LIBXML_DOTTED_VERSION' ) ? LIBXML_DOTTED_VERSION : null,
					'domHtmlDocument' => true,
				) : null,
				'error'         => $available ? null : 'Dom\\HTMLDocument is not available.',
			);
			return $this->metadata;
		}
		if ( self::KIND_CHROME_CDP === $this->kind ) {
			if ( null === $this->chrome_renderer ) {
				throw new \LogicException( 'Chrome renderer is missing.' );
			}
			$this->metadata = $this->chrome_renderer->metadata();
			return $this->metadata;
		}

		try {
			$this->metadata = $this->source_metadata();
		} catch ( \Throwable $error ) {
			$this->metadata = array(
				'schemaVersion' => self::METADATA_SCHEMA_VERSION,
				'kind'          => $this->kind,
				'available'     => false,
				'identity'      => null,
				'error'         => $error->getMessage(),
			);
		}
		return $this->metadata;
	}

	/** Return reasons the current oracle cannot faithfully replay recorded output. */
	public static function identity_mismatches( $recorded, array $current ): array {
		$recorded_error = self::metadata_validation_error( $recorded );
		$current_error  = self::metadata_validation_error( $current );
		if ( null !== $recorded_error ) {
			return array( 'recorded oracle metadata is invalid: ' . $recorded_error );
		}
		if ( null !== $current_error ) {
			return array( 'current oracle metadata is invalid: ' . $current_error );
		}
		if ( $recorded['kind'] !== $current['kind'] ) {
			return array( "oracle kind differs (recorded {$recorded['kind']}, current {$current['kind']})" );
		}
		if ( true !== $recorded['available'] ) {
			return array( 'recorded oracle is unavailable' );
		}
		if ( true !== $current['available'] ) {
			return array( 'current oracle is unavailable' );
		}
		if ( ! hash_equals( self::canonical_json( $recorded['identity'] ), self::canonical_json( $current['identity'] ) ) ) {
			return array( 'oracle identity differs' );
		}
		return array();
	}

	public static function identity_sha256( array $metadata ): string {
		$error = self::metadata_validation_error( $metadata );
		if ( null !== $error || true !== $metadata['available'] ) {
			throw new \InvalidArgumentException( 'Cannot hash invalid or unavailable oracle metadata' . ( null === $error ? '.' : ': ' . $error ) );
		}
		return hash( 'sha256', self::canonical_json( $metadata ) );
	}

	public function replay_options(): array {
		$options = array( 'domOracle' => $this->kind );
		if ( self::KIND_LEXBOR_SOURCE === $this->kind && null !== $this->source_binary ) {
			$options['lexborOracleBin'] = $this->source_binary;
		} elseif ( self::KIND_HTML5EVER_SOURCE === $this->kind && null !== $this->source_binary ) {
			$options['html5everOracleBin'] = $this->source_binary;
		} elseif ( self::KIND_CHROME_CDP === $this->kind && null !== $this->chrome_renderer ) {
			$options['chromeOracleScript'] = $this->chrome_renderer->script();
			$options['chromeExecutable'] = $this->chrome_renderer->chrome_executable();
			$options['nodeBin'] = $this->chrome_renderer->node_executable();
			$options['chromeStartupTimeoutMs'] = $this->chrome_renderer->startup_timeout_ms();
		}
		if ( self::DEFAULT_TIMEOUT_MS !== $this->timeout_ms ) {
			$options['oracleTimeoutMs'] = $this->timeout_ms;
		}
		return $options;
	}

	public function worker_args(): array {
		$args = array( '--dom-oracle', $this->kind );
		if ( self::KIND_LEXBOR_SOURCE === $this->kind && null !== $this->source_binary ) {
			$args[] = '--lexbor-oracle-bin';
			$args[] = $this->source_binary;
		} elseif ( self::KIND_HTML5EVER_SOURCE === $this->kind && null !== $this->source_binary ) {
			$args[] = '--html5ever-oracle-bin';
			$args[] = $this->source_binary;
		} elseif ( self::KIND_CHROME_CDP === $this->kind && null !== $this->chrome_renderer ) {
			$args[] = '--chrome-oracle-script';
			$args[] = $this->chrome_renderer->script();
			if ( '' !== $this->chrome_renderer->chrome_executable() ) {
				$args[] = '--chrome-executable';
				$args[] = $this->chrome_renderer->chrome_executable();
			}
			$args[] = '--node-bin';
			$args[] = $this->chrome_renderer->node_executable();
			$args[] = '--chrome-startup-timeout-ms';
			$args[] = (string) $this->chrome_renderer->startup_timeout_ms();
		}
		if ( self::DEFAULT_TIMEOUT_MS !== $this->timeout_ms ) {
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
		if ( self::KIND_CHROME_CDP === $this->kind ) {
			if ( null === $this->chrome_renderer ) {
				throw new \LogicException( 'Chrome renderer is missing.' );
			}
			return $this->chrome_renderer->render( $html, $mode, $limits, $fragment_context );
		}

		$metadata = $this->metadata();
		if ( true !== $metadata['available'] ) {
			return $this->infrastructure_result( 'Source oracle is unavailable: ' . (string) $metadata['error'], null );
		}

		$max_nodes      = self::positive_limit( $limits, 'maxNodes', 3000 );
		$max_depth      = self::positive_limit( $limits, 'maxDepth', 512 );
		$max_tree_bytes = self::positive_limit( $limits, 'maxTreeBytes', 16777216 );
		try {
			$this->assert_source_identity_current( $metadata['identity'] );
			$process = $this->run_private_process(
				array(
					'--mode', $mode,
					'--context', $fragment_context,
					'--max-nodes', (string) $max_nodes,
					'--max-depth', (string) $max_depth,
					'--max-tree-bytes', (string) $max_tree_bytes,
					'--input', '@INPUT@',
				),
				$html,
				$metadata['identity']['binarySha256']
			);
			$this->assert_source_identity_current( $metadata['identity'] );
			if (
				$process['timedOut'] ||
				$process['stdoutOverflow'] ||
				$process['stderrOverflow'] ||
				$process['controlOverflow'] ||
				null === $process['code'] ||
				$process['supervisorKilled'] ||
				$process['supervisorUnexpectedExit']
			) {
				throw new \RuntimeException( 'Source oracle process did not complete within its authenticated transport limits.' );
			}
			$decoded = StrictJsonParser::decode( $process['stdout'] );
			$result  = $this->validate_render_response( $decoded, $metadata['identity'], $max_nodes, $max_tree_bytes );
			$status = $decoded['status'];
			$expected_code = self::KIND_LEXBOR_SOURCE === $this->kind && 'error' === $status ? 1 : 0;
			if ( $expected_code !== $process['code'] ) {
				throw new \RuntimeException( 'Source oracle exit code does not match its validated outcome.' );
			}
			$result['oracle']  = $metadata;
			$result['process'] = self::compact_process( $process );
			return $result;
		} catch ( \Throwable $error ) {
			return $this->infrastructure_result( $error->getMessage(), $process ?? null );
		}
	}

	public function close(): void {
		if ( null !== $this->chrome_renderer ) {
			$this->chrome_renderer->close();
		}
	}

	/**
	 * Run an owner's complete renderer lifetime and always surface cleanup.
	 *
	 * @return mixed
	 */
	public static function with_explicit_close( self $renderer, callable $operation ) {
		$value = null;
		$operation_error = null;
		try {
			$value = $operation( $renderer );
		} catch ( \Throwable $error ) {
			$operation_error = $error;
		}

		$cleanup_error = null;
		try {
			$renderer->close();
		} catch ( \Throwable $error ) {
			$cleanup_error = $error;
		}

		if ( null !== $operation_error ) {
			if ( null !== $cleanup_error ) {
				throw new \RuntimeException(
					$operation_error->getMessage() . '; oracle cleanup failed: ' . $cleanup_error->getMessage(),
					0,
					$operation_error
				);
			}
			throw $operation_error;
		}
		if ( null !== $cleanup_error ) {
			throw $cleanup_error;
		}

		return $value;
	}

	public function recommended_process_timeout_ms( string $checks, int $non_chrome_fallback ): int {
		if ( $non_chrome_fallback < 1 ) {
			throw new \InvalidArgumentException( 'Expected the non-Chrome process timeout fallback to be positive.' );
		}
		if ( ! in_array( $checks, array( 'baseline', 'full', 'sampled' ), true ) ) {
			$checks = 'unknown';
		}
		return null === $this->chrome_renderer
			? $non_chrome_fallback
			: $this->chrome_renderer->recommended_process_timeout_ms( $checks );
	}

	private function source_metadata(): array {
		if ( ! is_string( $this->source_binary ) || '' === $this->source_binary ) {
			throw new \RuntimeException( 'Source oracle binary path is missing.' );
		}
		clearstatcache( true, $this->source_binary );
		$binary = realpath( $this->source_binary );
		if ( false === $binary || ! is_file( $binary ) || ! is_executable( $binary ) ) {
			throw new \RuntimeException( 'Source oracle binary is not an executable regular file.' );
		}
		$manifest_path = dirname( $binary ) . '/build-manifest.json';
		$manifest_before = self::read_stable_file( $manifest_path, self::MANIFEST_MAX_BYTES );
		$manifest = StrictJsonParser::decode( $manifest_before );
		if ( ! is_array( $manifest ) ) {
			throw new \RuntimeException( 'Source oracle build manifest is not an object.' );
		}
		$process = $this->run_private_process( array( '--version' ) );
		if (
			0 !== $process['code'] ||
			$process['timedOut'] ||
			$process['stdoutOverflow'] ||
			$process['stderrOverflow'] ||
			$process['controlOverflow'] ||
			$process['supervisorKilled'] ||
			$process['supervisorUnexpectedExit']
		) {
			throw new \RuntimeException( 'Source oracle identity probe failed.' );
		}
		$manifest_after = self::read_stable_file( $manifest_path, self::MANIFEST_MAX_BYTES );
		if ( ! hash_equals( hash( 'sha256', $manifest_before ), hash( 'sha256', $manifest_after ) ) ) {
			throw new \RuntimeException( 'Source oracle build manifest changed during identity probing.' );
		}
		if ( ! hash_equals( $process['sourceSha256'], self::hash_stable_file( $binary, true ) ) ) {
			throw new \RuntimeException( 'Source oracle executable changed during identity probing.' );
		}
		$version = StrictJsonParser::decode( $process['stdout'] );
		$oracle  = $this->validate_version_response( $version );
		$identity = self::KIND_LEXBOR_SOURCE === $this->kind
			? $this->lexbor_identity( $manifest, $oracle, $process['sourceSha256'] )
			: $this->html5ever_identity( $manifest, $oracle, $process['sourceSha256'], dirname( dirname( $binary ) ) );
		$metadata = array(
			'schemaVersion' => self::METADATA_SCHEMA_VERSION,
			'kind'          => $this->kind,
			'available'     => true,
			'identity'      => $identity,
			'error'         => null,
		);
		$error = self::metadata_validation_error( $metadata );
		if ( null !== $error ) {
			throw new \RuntimeException( 'Constructed source oracle identity is invalid: ' . $error );
		}
		$this->source_manifest_sha256 = hash( 'sha256', $manifest_after );
		return $metadata;
	}

	private function assert_source_identity_current( array $expected_identity ): void {
		if ( null === $this->source_manifest_sha256 || ! is_string( $this->source_binary ) ) {
			throw new \RuntimeException( 'Source oracle identity has not been pinned.' );
		}
		clearstatcache( true, $this->source_binary );
		$binary = realpath( $this->source_binary );
		if ( false === $binary || ! is_file( $binary ) || ! is_executable( $binary ) ) {
			throw new \RuntimeException( 'Source oracle binary is no longer an executable regular file.' );
		}
		$manifest_contents = self::read_stable_file( dirname( $binary ) . '/build-manifest.json', self::MANIFEST_MAX_BYTES );
		if ( ! hash_equals( $this->source_manifest_sha256, hash( 'sha256', $manifest_contents ) ) ) {
			throw new \RuntimeException( 'Source oracle build manifest no longer matches its verified identity.' );
		}
		$manifest = StrictJsonParser::decode( $manifest_contents );
		if ( ! is_array( $manifest ) ) {
			throw new \RuntimeException( 'Source oracle build manifest is not an object.' );
		}
		$binary_sha256 = self::hash_stable_file( $binary, true );
		if ( self::KIND_LEXBOR_SOURCE === $this->kind ) {
			$oracle = array(
				'kind'          => self::KIND_LEXBOR_SOURCE,
				'lexborCommit'  => $expected_identity['lexborCommit'] ?? null,
				'lexborVersion' => $expected_identity['lexborVersion'] ?? null,
			);
			$current_identity = $this->lexbor_identity( $manifest, $oracle, $binary_sha256 );
		} else {
			$oracle = array(
				'kind'                         => self::KIND_HTML5EVER_SOURCE,
				'available'                    => true,
				'html5everVersion'             => $expected_identity['html5everVersion'] ?? null,
				'html5everChecksum'            => $expected_identity['html5everChecksum'] ?? null,
				'markup5everRcdomVersion'      => $expected_identity['markup5everRcdomVersion'] ?? null,
				'markup5everRcdomChecksum'     => $expected_identity['markup5everRcdomChecksum'] ?? null,
				'rustToolchain'                => $expected_identity['rustToolchain'] ?? null,
				'cargoLockSha256'              => $expected_identity['cargoLockSha256'] ?? null,
				'buildIdentity'                => $expected_identity['buildIdentity'] ?? null,
			);
			$current_identity = $this->html5ever_identity( $manifest, $oracle, $binary_sha256, dirname( dirname( $binary ) ) );
		}
		if ( ! hash_equals( self::canonical_json( $expected_identity ), self::canonical_json( $current_identity ) ) ) {
			throw new \RuntimeException( 'Source oracle identity changed after it was verified.' );
		}
	}

	private function validate_version_response( $version ): array {
		if ( ! is_array( $version ) || ! self::exact_keys( $version, array( 'status', 'oracle' ) ) || 'ok' !== $version['status'] || ! is_array( $version['oracle'] ) ) {
			throw new \RuntimeException( 'Source oracle returned an invalid version response schema.' );
		}
		$oracle = $version['oracle'];
		if ( self::KIND_LEXBOR_SOURCE === $this->kind ) {
			if (
				! self::exact_keys( $oracle, array( 'kind', 'lexborCommit', 'lexborVersion' ) ) ||
				self::KIND_LEXBOR_SOURCE !== $oracle['kind'] ||
				! self::matches( $oracle['lexborCommit'], '/^[0-9a-f]{40}$/' ) ||
				! self::nonempty_string( $oracle['lexborVersion'] )
			) {
				throw new \RuntimeException( 'Lexbor oracle returned invalid version identity.' );
			}
		} elseif (
			! self::exact_keys(
				$oracle,
				array( 'kind', 'available', 'html5everVersion', 'html5everChecksum', 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain', 'cargoLockSha256', 'buildIdentity' )
			) ||
			self::KIND_HTML5EVER_SOURCE !== $oracle['kind'] ||
			true !== $oracle['available'] ||
			! self::nonempty_string( $oracle['html5everVersion'] ) ||
			! self::matches( $oracle['html5everChecksum'], '/^[0-9a-f]{64}$/' ) ||
			! self::nonempty_string( $oracle['markup5everRcdomVersion'] ) ||
			! self::matches( $oracle['markup5everRcdomChecksum'], '/^[0-9a-f]{64}$/' ) ||
			! self::nonempty_string( $oracle['rustToolchain'] ) ||
			! self::matches( $oracle['cargoLockSha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::matches( $oracle['buildIdentity'], '/^[0-9a-f]{64}$/' )
		) {
			throw new \RuntimeException( 'html5ever oracle returned invalid version identity.' );
		}
		return $oracle;
	}

	private function lexbor_identity( array $manifest, array $oracle, string $binary_sha256 ): array {
		if (
			! self::exact_keys( $manifest, array( 'kind', 'requestedRef', 'resolvedCommit', 'upstream', 'builtAt', 'binarySha256', 'compiler', 'cmake' ) ) ||
			'html-api-fuzz-lexbor-build' !== $manifest['kind'] ||
			! self::nonempty_string( $manifest['requestedRef'] ) ||
			! self::matches( $manifest['resolvedCommit'], '/^[0-9a-f]{40}$/' ) ||
			'https://github.com/lexbor/lexbor.git' !== $manifest['upstream'] ||
			! self::nonempty_string( $manifest['builtAt'] ) ||
			! self::matches( $manifest['binarySha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::nonempty_string( $manifest['compiler'] ) ||
			! self::nonempty_string( $manifest['cmake'] ) ||
			! hash_equals( $binary_sha256, $manifest['binarySha256'] ) ||
			! hash_equals( $oracle['lexborCommit'], $manifest['resolvedCommit'] )
		) {
			throw new \RuntimeException( 'Lexbor build manifest does not agree with its executable and self-report.' );
		}
		return array(
			'schemaVersion' => 1,
			'kind'          => self::KIND_LEXBOR_SOURCE,
			'binarySha256'  => $binary_sha256,
			'lexborCommit'  => $oracle['lexborCommit'],
			'lexborVersion' => $oracle['lexborVersion'],
			'build'         => array(
				'kind'           => $manifest['kind'],
				'requestedRef'   => $manifest['requestedRef'],
				'resolvedCommit' => $manifest['resolvedCommit'],
				'upstream'       => $manifest['upstream'],
				'compiler'       => $manifest['compiler'],
				'cmake'          => $manifest['cmake'],
			),
		);
	}

	private function html5ever_identity( array $manifest, array $oracle, string $binary_sha256, string $project_root ): array {
		$manifest_keys = array(
			'schemaVersion', 'kind', 'publicationProtocol', 'builtAt', 'buildIdentity',
			'cargoTomlSha256', 'cargoLockSha256', 'rustToolchainSha256', 'sourceSha256',
			'rustc', 'cargo', 'html5ever', 'markup5everRcdom', 'binarySha256',
		);
		if (
			! self::exact_keys( $manifest, $manifest_keys ) ||
			1 !== $manifest['schemaVersion'] ||
			'html-api-fuzz-html5ever-build' !== $manifest['kind'] ||
			'manifest-last-v1' !== $manifest['publicationProtocol'] ||
			! self::nonempty_string( $manifest['builtAt'] ) ||
			! self::matches( $manifest['buildIdentity'], '/^[0-9a-f]{64}$/' ) ||
			! self::matches( $manifest['cargoTomlSha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::matches( $manifest['cargoLockSha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::matches( $manifest['rustToolchainSha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::matches( $manifest['sourceSha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::nonempty_string( $manifest['rustc'] ) ||
			! self::nonempty_string( $manifest['cargo'] ) ||
			! self::matches( $manifest['binarySha256'], '/^[0-9a-f]{64}$/' ) ||
			! self::package_identity_valid( $manifest['html5ever'] ) ||
			! self::package_identity_valid( $manifest['markup5everRcdom'] )
		) {
			throw new \RuntimeException( 'html5ever build manifest schema is invalid.' );
		}
		$paths = array(
			'cargoTomlSha256'     => $project_root . '/Cargo.toml',
			'cargoLockSha256'     => $project_root . '/Cargo.lock',
			'rustToolchainSha256' => $project_root . '/rust-toolchain.toml',
			'sourceSha256'        => $project_root . '/src/main.rs',
		);
		$contents = array();
		foreach ( $paths as $field => $path ) {
			$contents[ $field ] = self::read_stable_file( $path, 16777216 );
			if ( ! hash_equals( $manifest[ $field ], hash( 'sha256', $contents[ $field ] ) ) ) {
				throw new \RuntimeException( 'html5ever checked-in build input does not match its manifest.' );
			}
		}
		$build_identity = hash(
			'sha256',
			"Cargo.toml {$manifest['cargoTomlSha256']}\n" .
			"Cargo.lock {$manifest['cargoLockSha256']}\n" .
			"rust-toolchain.toml {$manifest['rustToolchainSha256']}\n" .
			"src/main.rs {$manifest['sourceSha256']}\n"
		);
		$locked_html5ever = self::cargo_lock_package( $contents['cargoLockSha256'], 'html5ever' );
		$locked_rcdom = self::cargo_lock_package( $contents['cargoLockSha256'], 'markup5ever_rcdom' );
		if (
			! hash_equals( $binary_sha256, $manifest['binarySha256'] ) ||
			! hash_equals( $build_identity, $manifest['buildIdentity'] ) ||
			$manifest['html5ever'] !== $locked_html5ever ||
			$manifest['markup5everRcdom'] !== $locked_rcdom ||
			$oracle['html5everVersion'] !== $locked_html5ever['version'] ||
			$oracle['html5everChecksum'] !== $locked_html5ever['checksum'] ||
			$oracle['markup5everRcdomVersion'] !== $locked_rcdom['version'] ||
			$oracle['markup5everRcdomChecksum'] !== $locked_rcdom['checksum'] ||
			$oracle['rustToolchain'] !== self::rust_toolchain_channel( $contents['rustToolchainSha256'] ) ||
			! hash_equals( $oracle['cargoLockSha256'], $manifest['cargoLockSha256'] ) ||
			! hash_equals( $oracle['buildIdentity'], $manifest['buildIdentity'] )
		) {
			throw new \RuntimeException( 'html5ever build manifest, lockfile, executable, and self-report do not agree.' );
		}
		return array(
			'schemaVersion'              => 1,
			'kind'                       => self::KIND_HTML5EVER_SOURCE,
			'binarySha256'               => $binary_sha256,
			'html5everVersion'            => $oracle['html5everVersion'],
			'html5everChecksum'           => $oracle['html5everChecksum'],
			'markup5everRcdomVersion'     => $oracle['markup5everRcdomVersion'],
			'markup5everRcdomChecksum'    => $oracle['markup5everRcdomChecksum'],
			'rustToolchain'               => $oracle['rustToolchain'],
			'cargoLockSha256'             => $oracle['cargoLockSha256'],
			'buildIdentity'               => $oracle['buildIdentity'],
			'build'                       => array(
				'schemaVersion'       => $manifest['schemaVersion'],
				'kind'                => $manifest['kind'],
				'publicationProtocol' => $manifest['publicationProtocol'],
				'cargoTomlSha256'     => $manifest['cargoTomlSha256'],
				'cargoLockSha256'     => $manifest['cargoLockSha256'],
				'rustToolchainSha256' => $manifest['rustToolchainSha256'],
				'sourceSha256'        => $manifest['sourceSha256'],
				'rustc'               => $manifest['rustc'],
				'cargo'               => $manifest['cargo'],
				'html5ever'           => $manifest['html5ever'],
				'markup5everRcdom'    => $manifest['markup5everRcdom'],
			),
		);
	}

	private function validate_render_response( $response, array $identity, int $max_nodes, int $max_tree_bytes ): array {
		if ( ! is_array( $response ) || ! is_string( $response['status'] ?? null ) ) {
			throw new \RuntimeException( 'Source oracle did not return a result object.' );
		}
		$status = $response['status'];
		$keys = array(
			'ok'          => array( 'status', 'oracle', 'tree', 'treeBase64', 'nodeCount' ),
			'unsupported' => array( 'status', 'oracle', 'nodeCount', 'failureClass', 'unsupported' ),
			'error'       => array( 'status', 'oracle', 'nodeCount', 'failureClass', 'error' ),
		);
		if ( ! isset( $keys[ $status ] ) || ! self::exact_keys( $response, $keys[ $status ] ) ) {
			throw new \RuntimeException( 'Source oracle result has an invalid exact schema.' );
		}
		if ( ! is_int( $response['nodeCount'] ) || $response['nodeCount'] < 0 || $response['nodeCount'] > $max_nodes + 1 ) {
			throw new \RuntimeException( 'Source oracle returned an invalid node count.' );
		}
		$this->validate_render_oracle_identity( $response['oracle'], $identity );
		if ( 'ok' === $status ) {
			if ( ! is_string( $response['tree'] ) || ! is_string( $response['treeBase64'] ) ) {
				throw new \RuntimeException( 'Source oracle returned a non-string tree.' );
			}
			$decoded_tree = base64_decode( $response['treeBase64'], true );
			if (
				false === $decoded_tree ||
				! hash_equals( base64_encode( $decoded_tree ), $response['treeBase64'] ) ||
				! hash_equals( $decoded_tree, $response['tree'] ) ||
				strlen( $decoded_tree ) > $max_tree_bytes
			) {
				throw new \RuntimeException( 'Source oracle tree and canonical treeBase64 do not agree with the byte limit.' );
			}
			return array( 'status' => TreeRenderer::STATUS_OK, 'tree' => $decoded_tree, 'nodeCount' => $response['nodeCount'] );
		}
		if ( ! is_string( $response['failureClass'] ) ) {
			throw new \RuntimeException( 'Source oracle failure class is invalid.' );
		}
		if ( 'unsupported' === $status ) {
			if (
				'oracle-unsupported' !== $response['failureClass'] ||
				! is_array( $response['unsupported'] ) ||
				! self::exact_keys( $response['unsupported'], array( 'message' ) ) ||
				! is_string( $response['unsupported']['message'] )
			) {
				throw new \RuntimeException( 'Source oracle returned an invalid unsupported outcome.' );
			}
			return array(
				'status'       => TreeRenderer::STATUS_UNSUPPORTED,
				'failureClass' => 'oracle-unsupported',
				'unsupported'  => $response['unsupported'],
				'nodeCount'    => $response['nodeCount'],
			);
		}
		$allowed = array( 'oracle-parse-error', 'node-limit-exceeded', 'depth-limit-exceeded', 'tree-byte-limit-exceeded', 'oracle-renderer-error' );
		if ( ! in_array( $response['failureClass'], $allowed, true ) || ! is_string( $response['error'] ) ) {
			throw new \RuntimeException( 'Source oracle returned an untrusted error outcome.' );
		}
		$result = array(
			'status'       => TreeRenderer::STATUS_ERROR,
			'failureClass' => $response['failureClass'],
			'error'        => $response['error'],
			'nodeCount'    => $response['nodeCount'],
		);
		if ( 'oracle-renderer-error' === $response['failureClass'] ) {
			$result['infrastructure'] = true;
		}
		return $result;
	}

	private function validate_render_oracle_identity( $oracle, array $identity ): void {
		if ( ! is_array( $oracle ) ) {
			throw new \RuntimeException( 'Source oracle result identity is missing.' );
		}
		if ( self::KIND_LEXBOR_SOURCE === $this->kind ) {
			if (
				! self::exact_keys( $oracle, array( 'kind', 'lexborCommit', 'lexborVersion' ) ) ||
				self::KIND_LEXBOR_SOURCE !== $oracle['kind'] ||
				$identity['lexborCommit'] !== $oracle['lexborCommit'] ||
				$identity['lexborVersion'] !== $oracle['lexborVersion']
			) {
				throw new \RuntimeException( 'Lexbor result identity differs from its verified identity.' );
			}
			return;
		}
		$expected = array(
			'kind'                         => self::KIND_HTML5EVER_SOURCE,
			'available'                    => true,
			'html5everVersion'             => $identity['html5everVersion'],
			'html5everChecksum'            => $identity['html5everChecksum'],
			'markup5everRcdomVersion'      => $identity['markup5everRcdomVersion'],
			'markup5everRcdomChecksum'     => $identity['markup5everRcdomChecksum'],
			'rustToolchain'                => $identity['rustToolchain'],
			'cargoLockSha256'              => $identity['cargoLockSha256'],
			'buildIdentity'                => $identity['buildIdentity'],
		);
		if ( ! self::exact_keys( $oracle, array_keys( $expected ) ) || $oracle !== $expected ) {
			throw new \RuntimeException( 'html5ever result identity differs from its verified identity.' );
		}
	}

	private function run_private_process( array $target_arguments, ?string $input = null, ?string $expected_sha256 = null ): array {
		if ( ! is_string( $this->source_binary ) ) {
			throw new \RuntimeException( 'Source oracle path is unavailable.' );
		}
		$supervisor_source = dirname( __DIR__ ) . '/oracle-process-supervisor.php';
		require_once $supervisor_source;
		$root  = self::create_ownership_root();
		$token = bin2hex( random_bytes( 16 ) );
		$ownership = null;
		try {
			self::write_private_file( $root . '/owner-token', $token . "\n", 0600 );
			$ownership = \HtmlApiFuzz\OracleProcessSupervisor\ownership_identity( $root );
			if ( ! is_array( $ownership ) ) {
				throw new \RuntimeException( 'Could not pin private oracle ownership identity.' );
			}
			\HtmlApiFuzz\OracleProcessSupervisor\register_ownership_identity( $root, $ownership['root'], $ownership['owner'] );
			$target_copy = self::copy_stable_private( $this->source_binary, $root . '/oracle', true );
			$supervisor_copy = self::copy_stable_private( $supervisor_source, $root . '/supervisor.php', false );
			if ( null !== $expected_sha256 && ! hash_equals( $expected_sha256, $target_copy['sha256'] ) ) {
				throw new \RuntimeException( 'Source oracle executable no longer matches its verified identity.' );
			}
			if ( null !== $input ) {
				self::write_private_file( $root . '/input.bin', $input, 0600 );
			}
			$private_arguments = array_map(
				static fn ( $argument ) => '@INPUT@' === $argument ? $root . '/input.bin' : (string) $argument,
				$target_arguments
			);
			$command = array(
				PHP_BINARY,
				$supervisor_copy['path'],
				'--root', $root,
				'--token', $token,
				'--root-dev', (string) $ownership['root']['dev'],
				'--root-ino', (string) $ownership['root']['ino'],
				'--owner-dev', (string) $ownership['owner']['dev'],
				'--owner-ino', (string) $ownership['owner']['ino'],
				'--target', $target_copy['path'],
				'--target-sha256', $target_copy['sha256'],
				'--supervisor-sha256', $supervisor_copy['sha256'],
				'--',
				...$private_arguments,
			);
			$process = $this->monitor_supervisor( $command, $root, $token, $target_copy, $supervisor_copy );
			$process['sourceSha256'] = $target_copy['sha256'];
			return $process;
		} catch ( \Throwable $error ) {
			if ( is_dir( $root ) ) {
				$state = \HtmlApiFuzz\OracleProcessSupervisor\read_state( $root, $token );
				if ( null === $state && ! self::remove_unstarted_root( $root, $token ) ) {
					throw new \RuntimeException( $error->getMessage() . '; private ownership root was retained at ' . $root, 0, $error );
				}
			}
			throw $error;
		}
	}

	private function monitor_supervisor( array $command, string $root, string $token, array $target_copy, array $supervisor_copy ): array {
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
			3 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( $command, $spec, $pipes, $root, null, array( 'bypass_shell' => true ) );
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start private oracle supervisor.' );
		}
		foreach ( array( 1, 2, 3 ) as $descriptor ) {
			stream_set_blocking( $pipes[ $descriptor ], false );
		}
		stream_set_blocking( $pipes[0], false );
		stream_set_write_buffer( $pipes[0], 0 );

		$stdout = '';
		$stderr = '';
		$control = '';
		$stdout_overflow = false;
		$stderr_overflow = false;
		$control_overflow = false;
		$timed_out = false;
		$shutdown_sent = false;
		$forced_supervisor_kill = false;
		$monitor_error = null;
		$owner_pipe_closed = false;
		$start = microtime( true );
		$shutdown_at = null;
		$last_status = proc_get_status( $process );
		$supervisor_pid = (int) ( $last_status['pid'] ?? 0 );
		$observed_exit_code = null;

		while ( true ) {
			self::drain_bounded( $pipes[1], $stdout, self::SOURCE_STDOUT_MAX_BYTES, $stdout_overflow );
			self::drain_bounded( $pipes[2], $stderr, self::SOURCE_STDERR_MAX_BYTES, $stderr_overflow );
			self::drain_bounded( $pipes[3], $control, self::CONTROL_MAX_BYTES, $control_overflow );
			$last_status = proc_get_status( $process );
			if ( ! $last_status['running'] ) {
				$observed_exit_code = isset( $last_status['exitcode'] ) && $last_status['exitcode'] >= 0 ? (int) $last_status['exitcode'] : null;
				break;
			}
			$elapsed_ms = ( microtime( true ) - $start ) * 1000;
			if ( ! $shutdown_sent && ( $elapsed_ms > $this->timeout_ms || $stdout_overflow || $stderr_overflow || $control_overflow ) ) {
				$timed_out = $elapsed_ms > $this->timeout_ms;
				$frame = json_encode( array( 'schemaVersion' => 1, 'command' => 'shutdown', 'token' => $token ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
				if ( strlen( $frame ) !== @fwrite( $pipes[0], $frame ) || ! @fflush( $pipes[0] ) ) {
					// Keeping the owner descriptor open still allows authenticated fallback.
				}
				$shutdown_sent = true;
				$shutdown_at = microtime( true );
			}
			if ( $shutdown_sent && null !== $shutdown_at && ( microtime( true ) - $shutdown_at ) * 1000 > self::CLEANUP_GRACE_MS ) {
				$state = \HtmlApiFuzz\OracleProcessSupervisor\read_state( $root, $token );
				$authenticated = is_array( $state ) ? \HtmlApiFuzz\OracleProcessSupervisor\authenticate_supervisor( $state ) : null;
				if ( null !== $authenticated && $supervisor_pid === $authenticated['pid'] && @posix_kill( $supervisor_pid, SIGKILL ) ) {
					$forced_supervisor_kill = true;
					$shutdown_at = null;
				} else {
					$race_status = proc_get_status( $process );
					if ( ! $race_status['running'] ) {
						$observed_exit_code = isset( $race_status['exitcode'] ) && $race_status['exitcode'] >= 0 ? (int) $race_status['exitcode'] : null;
						break;
					}
					$monitor_error = 'Timed-out oracle supervisor could not yet be authenticated for termination.';
					if ( ! $owner_pipe_closed ) {
						fclose( $pipes[0] );
						$owner_pipe_closed = true;
					}
					$shutdown_at = microtime( true );
				}
			}
			$read = array( $pipes[1], $pipes[2], $pipes[3] );
			$write = null;
			$except = null;
			@stream_select( $read, $write, $except, 0, 10000 );
		}

		self::drain_bounded( $pipes[1], $stdout, self::SOURCE_STDOUT_MAX_BYTES, $stdout_overflow );
		self::drain_bounded( $pipes[2], $stderr, self::SOURCE_STDERR_MAX_BYTES, $stderr_overflow );
		self::drain_bounded( $pipes[3], $control, self::CONTROL_MAX_BYTES, $control_overflow );
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		$closed_code = proc_close( $process );
		$exit_code = null !== $observed_exit_code ? $observed_exit_code : ( $closed_code >= 0 ? $closed_code : null );
		$events = array();
		$control_error = null;
		if ( $control_overflow ) {
			$control_error = 'Oracle supervisor control stream exceeded its byte limit.';
		} else {
			try {
				$events = self::parse_control_events( $control, $token );
			} catch ( \Throwable $error ) {
				$control_error = $error->getMessage();
			}
		}
		$cleanup_error = $this->finish_private_cleanup(
			$root,
			$token,
			$supervisor_pid,
			$target_copy,
			$supervisor_copy,
			$events
		);
		if ( null !== $cleanup_error ) {
			throw new \RuntimeException( $cleanup_error . '; evidence retained at ' . $root );
		}
		if ( null !== $control_error ) {
			throw new \RuntimeException( 'Oracle supervisor control protocol is invalid: ' . $control_error );
		}
		if ( null !== $monitor_error && ! $forced_supervisor_kill ) {
			throw new \RuntimeException( $monitor_error );
		}
		if ( empty( $events ) ) {
			throw new \RuntimeException( 'Oracle supervisor control protocol was missing.' );
		}
		$last_event = $events[ count( $events ) - 1 ];
		$unexpected_supervisor_exit = ! $forced_supervisor_kill && 'cleaned' !== $last_event['event'];

		return array(
			'code'             => $exit_code,
			'timedOut'         => $timed_out,
			'durationMs'       => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'stdout'           => $stdout,
			'stderr'           => $stderr,
			'stdoutOverflow'   => $stdout_overflow,
			'stderrOverflow'   => $stderr_overflow,
			'controlOverflow'  => $control_overflow,
			'cleanupVerified'  => true,
			'supervisorKilled' => $forced_supervisor_kill,
			'supervisorUnexpectedExit' => $unexpected_supervisor_exit,
		);
	}

	private function finish_private_cleanup( string $root, string $token, int $supervisor_pid, array $target_copy, array $supervisor_copy, array $events ): ?string {
		$ready = null;
		$anchor = null;
		foreach ( $events as $event ) {
			if ( 'supervisor-ready' === $event['event'] ) {
				$ready = $event['supervisor'];
			} elseif ( 'anchor-ready' === $event['event'] ) {
				$anchor = $event['anchor'];
			}
		}
		if ( is_array( $ready ) ) {
			$current = \HtmlApiFuzz\OracleProcessSupervisor\process_identity( $ready['pid'] );
			if ( null !== $current && \HtmlApiFuzz\OracleProcessSupervisor\same_identity( $ready, $current ) ) {
				return 'Authenticated oracle supervisor remained after process completion';
			}
		}
		if ( ! is_dir( $root ) ) {
			if ( is_array( $anchor ) ) {
				$members = \HtmlApiFuzz\OracleProcessSupervisor\session_group_members( $anchor['sid'], $anchor['pgid'] );
				if ( null === $members || array() !== $members ) {
					return 'Oracle target group absence could not be verified after root removal';
				}
			}
			return null;
		}
		$state = \HtmlApiFuzz\OracleProcessSupervisor\read_state( $root, $token );
		$state_error = self::state_validation_error( $state, $root, $token, $target_copy, $supervisor_copy, $supervisor_pid );
		if ( null !== $state_error ) {
			return 'Oracle fallback state is invalid: ' . $state_error;
		}
		$phase = $state['phase'];
		if ( 'supervisor-ready' === $phase ) {
			$deadline = hrtime( true ) + 2000000000;
			do {
				$members = \HtmlApiFuzz\OracleProcessSupervisor\session_members( $state['supervisor']['sid'] );
				if ( null === $members ) {
					return 'Oracle supervisor session absence could not be inspected';
				}
				if ( array() === $members ) {
					break;
				}
				usleep( 10000 );
			} while ( hrtime( true ) < $deadline );
			if ( array() !== $members ) {
				return 'Unpublished oracle session members survived supervisor exit';
			}
		} elseif ( in_array( $phase, array( 'anchor-ready', 'gated', 'running', 'target-exit', 'cleaning' ), true ) ) {
			$error = \HtmlApiFuzz\OracleProcessSupervisor\cleanup_anchored_group( $root, $token );
			if ( null !== $error ) {
				return $error;
			}
		} elseif ( 'cleaned' === $phase ) {
			if ( is_array( $state['anchor'] ) ) {
				$members = \HtmlApiFuzz\OracleProcessSupervisor\session_group_members( $state['anchor']['sid'], $state['anchor']['pgid'] );
				if ( null === $members || array() !== $members ) {
					return 'Cleaned oracle state did not prove group absence';
				}
			}
		} else {
			return 'Oracle supervisor retained an unsafe cleanup phase';
		}
		if ( ! \HtmlApiFuzz\OracleProcessSupervisor\remove_owned_root( $root, $token ) ) {
			return 'Authenticated oracle ownership root could not be removed';
		}
		return is_dir( $root ) ? 'Authenticated oracle ownership root survived removal' : null;
	}

	private static function parse_control_events( string $control, string $token ): array {
		if ( '' === $control || ! str_ends_with( $control, "\n" ) ) {
			throw new \RuntimeException( 'Oracle supervisor control stream is incomplete.' );
		}
		$events = array();
		$seen = array();
		foreach ( explode( "\n", substr( $control, 0, -1 ) ) as $line ) {
			$event = StrictJsonParser::decode( $line );
			if ( ! is_array( $event ) || 1 !== ( $event['schemaVersion'] ?? null ) || $token !== ( $event['token'] ?? null ) || ! is_string( $event['event'] ?? null ) ) {
				throw new \RuntimeException( 'Oracle supervisor emitted an unauthenticated control event.' );
			}
			$name = $event['event'];
			if ( isset( $seen[ $name ] ) ) {
				throw new \RuntimeException( 'Oracle supervisor repeated a control phase.' );
			}
			$seen[ $name ] = true;
			if ( 'supervisor-ready' === $name ) {
				if ( ! self::exact_keys( $event, array( 'schemaVersion', 'event', 'token', 'supervisor' ) ) || null !== self::process_document_error( $event['supervisor'] ) ) {
					throw new \RuntimeException( 'Invalid supervisor-ready control event.' );
				}
			} elseif ( 'anchor-ready' === $name ) {
				if ( ! self::exact_keys( $event, array( 'schemaVersion', 'event', 'token', 'anchor' ) ) || null !== self::process_document_error( $event['anchor'] ) ) {
					throw new \RuntimeException( 'Invalid anchor-ready control event.' );
				}
			} elseif ( 'running' === $name ) {
				if ( ! self::exact_keys( $event, array( 'schemaVersion', 'event', 'token', 'target' ) ) || null !== self::process_document_error( $event['target'] ) ) {
					throw new \RuntimeException( 'Invalid running control event.' );
				}
			} elseif ( 'cleaned' === $name ) {
				if ( ! self::exact_keys( $event, array( 'schemaVersion', 'event', 'token', 'ownerDead' ) ) || ! is_bool( $event['ownerDead'] ) ) {
					throw new \RuntimeException( 'Invalid cleaned control event.' );
				}
			} elseif ( 'cleanup-failed' === $name ) {
				if ( ! self::exact_keys( $event, array( 'schemaVersion', 'event', 'token', 'error' ) ) || ! is_string( $event['error'] ) ) {
					throw new \RuntimeException( 'Invalid cleanup-failed control event.' );
				}
			} else {
				throw new \RuntimeException( 'Oracle supervisor emitted an unknown control event.' );
			}
			$events[] = $event;
		}
		$names = array_column( $events, 'event' );
		$valid_sequences = array(
			array( 'supervisor-ready' ),
			array( 'supervisor-ready', 'anchor-ready' ),
			array( 'supervisor-ready', 'anchor-ready', 'running' ),
			array( 'supervisor-ready', 'anchor-ready', 'running', 'cleaned' ),
			array( 'supervisor-ready', 'anchor-ready', 'running', 'cleanup-failed' ),
		);
		if ( ! in_array( $names, $valid_sequences, true ) ) {
			throw new \RuntimeException( 'Oracle supervisor control phases are out of order or incomplete in an invalid position.' );
		}
		$supervisor = $events[0]['supervisor'];
		if ( $supervisor['pid'] !== $supervisor['sid'] ) {
			throw new \RuntimeException( 'Oracle supervisor control identity is not its session leader.' );
		}
		if ( isset( $events[1] ) ) {
			$anchor = $events[1]['anchor'];
			if ( $anchor['pid'] !== $anchor['pgid'] || $anchor['sid'] !== $supervisor['sid'] || $anchor['pid'] === $supervisor['pid'] ) {
				throw new \RuntimeException( 'Oracle anchor control identity is inconsistent with its supervisor.' );
			}
		}
		if ( isset( $events[2] ) ) {
			$target = $events[2]['target'];
			if ( $target['sid'] !== $anchor['sid'] || $target['pgid'] !== $anchor['pgid'] || in_array( $target['pid'], array( $supervisor['pid'], $anchor['pid'] ), true ) ) {
				throw new \RuntimeException( 'Oracle target control identity is inconsistent with its anchor.' );
			}
		}
		return $events;
	}

	private static function state_validation_error( $state, string $root, string $token, array $target_copy, array $supervisor_copy, int $supervisor_pid ): ?string {
		if ( ! is_array( $state ) || ! self::exact_keys( $state, array( 'schemaVersion', 'token', 'root', 'rootIdentity', 'ownerIdentity', 'phase', 'supervisorPath', 'supervisorSha256', 'targetPath', 'targetSha256', 'supervisor', 'anchor', 'target', 'cleanupError' ) ) || ! \HtmlApiFuzz\OracleProcessSupervisor\valid_state_schema( $state ) ) {
			return 'state schema differs';
		}
		$actual_ownership = \HtmlApiFuzz\OracleProcessSupervisor\ownership_identity( $root );
		if (
			1 !== $state['schemaVersion'] ||
			$token !== $state['token'] ||
			$root !== $state['root'] ||
			null !== \HtmlApiFuzz\OracleProcessSupervisor\owner_error( $root, $token ) ||
			! \HtmlApiFuzz\OracleProcessSupervisor\valid_inode_identity( $state['rootIdentity'] ) ||
			! \HtmlApiFuzz\OracleProcessSupervisor\valid_inode_identity( $state['ownerIdentity'] ) ||
			$state['rootIdentity'] !== ( $actual_ownership['root'] ?? null ) ||
			$state['ownerIdentity'] !== ( $actual_ownership['owner'] ?? null ) ||
			$supervisor_copy['path'] !== $state['supervisorPath'] ||
			$supervisor_copy['sha256'] !== $state['supervisorSha256'] ||
			$target_copy['path'] !== $state['targetPath'] ||
			$target_copy['sha256'] !== $state['targetSha256'] ||
			$supervisor_pid !== ( $state['supervisor']['pid'] ?? null ) ||
			null !== self::process_document_error( $state['supervisor'] )
		) {
			return 'state ownership or executable identity differs';
		}
		foreach ( array( 'anchor', 'target' ) as $field ) {
			if ( null !== $state[ $field ] && null !== self::process_document_error( $state[ $field ] ) ) {
				return "state {$field} identity is invalid";
			}
		}
		if ( null !== $state['cleanupError'] && ! is_string( $state['cleanupError'] ) ) {
			return 'state cleanup error is invalid';
		}
		return null;
	}

	private static function process_document_error( $document ): ?string {
		if ( ! is_array( $document ) || ! self::exact_keys( $document, array( 'pid', 'pgid', 'sid', 'birth' ) ) ) {
			return 'process document schema differs';
		}
		return is_int( $document['pid'] ) && $document['pid'] > 1 &&
			is_int( $document['pgid'] ) && $document['pgid'] > 1 &&
			is_int( $document['sid'] ) && $document['sid'] > 1 &&
			self::nonempty_string( $document['birth'] ) ? null : 'process document values are invalid';
	}

	private static function drain_bounded( $stream, string &$buffer, int $maximum, bool &$overflow ): void {
		while ( true ) {
			$chunk = stream_get_contents( $stream, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				return;
			}
			if ( strlen( $buffer ) + strlen( $chunk ) > $maximum ) {
				$overflow = true;
				continue;
			}
			$buffer .= $chunk;
		}
	}

	private static function create_ownership_root(): string {
		$base = realpath( sys_get_temp_dir() );
		if ( false === $base ) {
			throw new \RuntimeException( 'Could not resolve the system temporary directory.' );
		}
		for ( $attempt = 0; $attempt < 20; ++$attempt ) {
			$root = $base . '/html-api-fuzz-oracle-' . bin2hex( random_bytes( 16 ) );
			if ( @mkdir( $root, 0700 ) ) {
				chmod( $root, 0700 );
				return $root;
			}
		}
		throw new \RuntimeException( 'Could not create a private oracle ownership root.' );
	}

	private static function write_private_file( string $path, string $contents, int $mode ): void {
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not create a private oracle file.' );
		}
		try {
			$written = 0;
			while ( $written < strlen( $contents ) ) {
				$count = fwrite( $handle, substr( $contents, $written, 65536 ) );
				if ( false === $count || 0 === $count ) {
					throw new \RuntimeException( 'Could not write a complete private oracle file.' );
				}
				$written += $count;
			}
			if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
				throw new \RuntimeException( 'Could not sync a private oracle file.' );
			}
		} finally {
			fclose( $handle );
		}
		chmod( $path, $mode );
	}

	private static function copy_stable_private( string $source, string $destination, bool $require_executable ): array {
		clearstatcache( true, $source );
		$resolved = realpath( $source );
		if ( false !== $resolved ) {
			clearstatcache( true, $resolved );
		}
		$lstat = false === $resolved ? false : @lstat( $resolved );
		if (
			false === $resolved ||
			false === $lstat ||
			( $lstat['mode'] & 0170000 ) !== 0100000 ||
			( $require_executable && ! is_executable( $resolved ) )
		) {
			throw new \RuntimeException( 'Oracle execution source is not a trusted regular file.' );
		}
		$input = @fopen( $resolved, 'rb' );
		$output = @fopen( $destination, 'xb' );
		if ( false === $input || false === $output ) {
			is_resource( $input ) && fclose( $input );
			is_resource( $output ) && fclose( $output );
			throw new \RuntimeException( 'Could not open a private oracle execution snapshot.' );
		}
		$before = fstat( $input );
		if ( ! is_array( $before ) || (int) $lstat['dev'] !== (int) $before['dev'] || (int) $lstat['ino'] !== (int) $before['ino'] || ( $before['mode'] & 0170000 ) !== 0100000 ) {
			fclose( $input );
			fclose( $output );
			throw new \RuntimeException( 'Oracle execution source changed before it was opened.' );
		}
		$hash = hash_init( 'sha256' );
		try {
			while ( ! feof( $input ) ) {
				$chunk = fread( $input, 65536 );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Could not read oracle execution source.' );
				}
				if ( '' !== $chunk ) {
					hash_update( $hash, $chunk );
					$offset = 0;
					while ( $offset < strlen( $chunk ) ) {
						$written = fwrite( $output, substr( $chunk, $offset ) );
						if ( false === $written || 0 === $written ) {
							throw new \RuntimeException( 'Could not write complete oracle execution snapshot.' );
						}
						$offset += $written;
					}
				}
			}
			$after = fstat( $input );
			if ( ! fflush( $output ) || ( function_exists( 'fsync' ) && ! fsync( $output ) ) ) {
				throw new \RuntimeException( 'Could not sync oracle execution snapshot.' );
			}
		} finally {
			fclose( $input );
			fclose( $output );
		}
		foreach ( array( 'dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime' ) as $field ) {
			if ( ! is_array( $before ) || ! is_array( $after ) || ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
				throw new \RuntimeException( 'Oracle execution source changed while it was copied.' );
			}
		}
		chmod( $destination, 0500 );
		$sha256 = hash_final( $hash );
		$private_stat = @lstat( $destination );
		if (
			false === $private_stat ||
			( $private_stat['mode'] & 0170000 ) !== 0100000 ||
			( $private_stat['mode'] & 0777 ) !== 0500 ||
			! hash_equals( $sha256, hash_file( 'sha256', $destination ) ?: '' )
		) {
			throw new \RuntimeException( 'Private oracle execution snapshot identity is invalid.' );
		}
		return array( 'path' => $destination, 'sha256' => $sha256, 'source' => $resolved );
	}

	private static function read_stable_file( string $path, int $maximum ): string {
		clearstatcache( true, $path );
		$resolved = realpath( $path );
		if ( false !== $resolved ) {
			clearstatcache( true, $resolved );
		}
		$stat = false === $resolved ? false : @lstat( $resolved );
		if ( false === $resolved || false === $stat || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
			throw new \RuntimeException( 'Required oracle identity file is not a regular file.' );
		}
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open required oracle identity file.' );
		}
		$before = fstat( $handle );
		if ( ! is_array( $before ) || (int) $stat['dev'] !== (int) $before['dev'] || (int) $stat['ino'] !== (int) $before['ino'] || ( $before['mode'] & 0170000 ) !== 0100000 ) {
			fclose( $handle );
			throw new \RuntimeException( 'Required oracle identity file changed before it was opened.' );
		}
		$contents = '';
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, min( 65536, $maximum + 1 - strlen( $contents ) ) );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Could not read required oracle identity file.' );
				}
				$contents .= $chunk;
				if ( strlen( $contents ) > $maximum ) {
					throw new \RuntimeException( 'Required oracle identity file exceeded its byte limit.' );
				}
			}
			$after = fstat( $handle );
		} finally {
			fclose( $handle );
		}
		clearstatcache( true, $resolved );
		$path_after = @lstat( $resolved );
		foreach ( array( 'dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime' ) as $field ) {
			if (
				! is_array( $before ) ||
				! is_array( $after ) ||
				! is_array( $path_after ) ||
				( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ||
				( $after[ $field ] ?? null ) !== ( $path_after[ $field ] ?? null )
			) {
				throw new \RuntimeException( 'Required oracle identity file changed while it was read.' );
			}
		}
		return $contents;
	}

	private static function hash_stable_file( string $path, bool $require_executable = false ): string {
		clearstatcache( true, $path );
		$resolved = realpath( $path );
		if ( false !== $resolved ) {
			clearstatcache( true, $resolved );
		}
		$stat = false === $resolved ? false : @lstat( $resolved );
		if (
			false === $resolved ||
			false === $stat ||
			( $stat['mode'] & 0170000 ) !== 0100000 ||
			( $require_executable && ! is_executable( $resolved ) )
		) {
			throw new \RuntimeException( 'Required oracle identity file is not a trusted regular file.' );
		}
		$handle = @fopen( $resolved, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open required oracle identity file.' );
		}
		$before = fstat( $handle );
		if ( ! is_array( $before ) || (int) $stat['dev'] !== (int) $before['dev'] || (int) $stat['ino'] !== (int) $before['ino'] || ( $before['mode'] & 0170000 ) !== 0100000 ) {
			fclose( $handle );
			throw new \RuntimeException( 'Required oracle identity file changed before it was opened.' );
		}
		$hash = hash_init( 'sha256' );
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 65536 );
				if ( false === $chunk ) {
					throw new \RuntimeException( 'Could not read required oracle identity file.' );
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
		foreach ( array( 'dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime' ) as $field ) {
			if (
				! is_array( $before ) ||
				! is_array( $after ) ||
				! is_array( $path_after ) ||
				( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ||
				( $after[ $field ] ?? null ) !== ( $path_after[ $field ] ?? null )
			) {
				throw new \RuntimeException( 'Required oracle identity file changed while it was hashed.' );
			}
		}
		return hash_final( $hash );
	}

	private static function remove_unstarted_root( string $root, string $token ): bool {
		require_once dirname( __DIR__ ) . '/oracle-process-supervisor.php';
		$ownership = \HtmlApiFuzz\OracleProcessSupervisor\ownership_identity( $root );
		if ( is_array( $ownership ) ) {
			try {
				\HtmlApiFuzz\OracleProcessSupervisor\register_ownership_identity( $root, $ownership['root'], $ownership['owner'] );
				return \HtmlApiFuzz\OracleProcessSupervisor\remove_owned_root( $root, $token );
			} catch ( \Throwable $ignored ) {
				return false;
			}
		}
		$stat = @lstat( $root );
		$entries = @scandir( $root );
		if (
			false === $stat ||
			( $stat['mode'] & 0170000 ) !== 0040000 ||
			( $stat['mode'] & 0777 ) !== 0700 ||
			( function_exists( 'posix_geteuid' ) && $stat['uid'] !== posix_geteuid() ) ||
			array( '.', '..' ) !== $entries
		) {
			return false;
		}
		return @rmdir( $root );
	}

	private function infrastructure_result( string $message, ?array $process ): array {
		$result = array(
			'status'       => TreeRenderer::STATUS_ERROR,
			'error'        => $message,
			'failureClass' => 'oracle-renderer-error',
			'infrastructure' => true,
			'oracle'       => $this->metadata(),
		);
		if ( is_array( $process ) ) {
			$result['process'] = self::compact_process( $process );
		}
		return $result;
	}

	private static function compact_process( array $process ): array {
		return array(
			'code'             => $process['code'] ?? null,
			'timedOut'         => $process['timedOut'] ?? false,
			'durationMs'       => $process['durationMs'] ?? null,
			'stdoutBytes'      => strlen( (string) ( $process['stdout'] ?? '' ) ),
			'stderrTail'       => substr( (string) ( $process['stderr'] ?? '' ), -1000 ),
			'stdoutOverflow'   => $process['stdoutOverflow'] ?? false,
			'stderrOverflow'   => $process['stderrOverflow'] ?? false,
			'cleanupVerified'  => $process['cleanupVerified'] ?? false,
			'supervisorKilled' => $process['supervisorKilled'] ?? false,
			'supervisorUnexpectedExit' => $process['supervisorUnexpectedExit'] ?? false,
		);
	}

	private static function metadata_validation_error( $metadata ): ?string {
		if ( ! is_array( $metadata ) || ! self::exact_keys( $metadata, array( 'schemaVersion', 'kind', 'available', 'identity', 'error' ) ) ) {
			return 'metadata schema differs';
		}
		if ( 1 !== $metadata['schemaVersion'] || ! in_array( $metadata['kind'], self::kinds(), true ) || ! is_bool( $metadata['available'] ) ) {
			return 'metadata header is invalid';
		}
		if ( ! $metadata['available'] ) {
			return null === $metadata['identity'] && self::nonempty_string( $metadata['error'] ) ? null : 'unavailable metadata values are invalid';
		}
		if ( null !== $metadata['error'] || ! is_array( $metadata['identity'] ) ) {
			return 'available metadata values are invalid';
		}
		$identity = $metadata['identity'];
		if ( ( $identity['kind'] ?? null ) !== $metadata['kind'] || 1 !== ( $identity['schemaVersion'] ?? null ) ) {
			return 'identity header differs';
		}
		if ( self::KIND_PHP_DOM === $metadata['kind'] ) {
			return self::exact_keys( $identity, array( 'schemaVersion', 'kind', 'phpVersion', 'phpVersionId', 'phpSapi', 'zendVersion', 'libxmlVersion', 'domHtmlDocument' ) ) &&
				self::nonempty_string( $identity['phpVersion'] ) && is_int( $identity['phpVersionId'] ) && $identity['phpVersionId'] > 0 &&
				self::nonempty_string( $identity['phpSapi'] ) && self::nonempty_string( $identity['zendVersion'] ) &&
				( null === $identity['libxmlVersion'] || self::nonempty_string( $identity['libxmlVersion'] ) ) && true === $identity['domHtmlDocument']
				? null : 'PHP DOM identity is invalid';
		}
		if ( self::KIND_LEXBOR_SOURCE === $metadata['kind'] ) {
			$build = $identity['build'] ?? null;
			return self::exact_keys( $identity, array( 'schemaVersion', 'kind', 'binarySha256', 'lexborCommit', 'lexborVersion', 'build' ) ) &&
				self::matches( $identity['binarySha256'], '/^[0-9a-f]{64}$/' ) && self::matches( $identity['lexborCommit'], '/^[0-9a-f]{40}$/' ) &&
				self::nonempty_string( $identity['lexborVersion'] ) && is_array( $build ) &&
				self::exact_keys( $build, array( 'kind', 'requestedRef', 'resolvedCommit', 'upstream', 'compiler', 'cmake' ) ) &&
				'html-api-fuzz-lexbor-build' === $build['kind'] && self::nonempty_string( $build['requestedRef'] ) &&
				$identity['lexborCommit'] === $build['resolvedCommit'] && 'https://github.com/lexbor/lexbor.git' === $build['upstream'] &&
				self::nonempty_string( $build['compiler'] ) && self::nonempty_string( $build['cmake'] )
				? null : 'Lexbor identity is invalid';
		}
		if ( self::KIND_CHROME_CDP === $metadata['kind'] ) {
			$keys = array(
				'schemaVersion', 'kind', 'platform', 'pinnedChromeVersion', 'chromeArchiveSha256',
				'expectedChromeExecutableSha256', 'chromeExecutableSha256', 'oracleScriptSha256',
				'fragmentContextsSha256', 'fragmentContexts', 'nodeExecutableSha256', 'nodeVersion',
				'chromeVersion', 'cdpProtocolVersion',
			);
			return self::exact_keys( $identity, $keys ) &&
				in_array( $identity['platform'], array( 'mac-arm64', 'mac-x64', 'linux64' ), true ) &&
				self::matches( $identity['pinnedChromeVersion'], '/^[0-9]+(?:\.[0-9]+){3}$/D' ) &&
				self::matches( $identity['chromeArchiveSha256'], '/^[0-9a-f]{64}$/D' ) &&
				self::matches( $identity['expectedChromeExecutableSha256'], '/^[0-9a-f]{64}$/D' ) &&
				$identity['expectedChromeExecutableSha256'] === $identity['chromeExecutableSha256'] &&
				self::matches( $identity['oracleScriptSha256'], '/^[0-9a-f]{64}$/D' ) &&
				self::matches( $identity['fragmentContextsSha256'], '/^[0-9a-f]{64}$/D' ) &&
				$identity['fragmentContexts'] === Generator::fragment_contexts() &&
				self::matches( $identity['nodeExecutableSha256'], '/^[0-9a-f]{64}$/D' ) &&
				self::matches( $identity['nodeVersion'], '/^v[0-9]+(?:\.[0-9]+){2}(?:[-+][0-9A-Za-z.-]+)?$/D' ) &&
				$identity['pinnedChromeVersion'] === $identity['chromeVersion'] &&
				self::nonempty_string( $identity['cdpProtocolVersion'] )
				? null : 'Chrome CDP identity is invalid';
		}
		$build = $identity['build'] ?? null;
		$identity_keys = array( 'schemaVersion', 'kind', 'binarySha256', 'html5everVersion', 'html5everChecksum', 'markup5everRcdomVersion', 'markup5everRcdomChecksum', 'rustToolchain', 'cargoLockSha256', 'buildIdentity', 'build' );
		$build_keys = array( 'schemaVersion', 'kind', 'publicationProtocol', 'cargoTomlSha256', 'cargoLockSha256', 'rustToolchainSha256', 'sourceSha256', 'rustc', 'cargo', 'html5ever', 'markup5everRcdom' );
		return self::exact_keys( $identity, $identity_keys ) && self::matches( $identity['binarySha256'], '/^[0-9a-f]{64}$/' ) &&
			self::nonempty_string( $identity['html5everVersion'] ) && self::matches( $identity['html5everChecksum'], '/^[0-9a-f]{64}$/' ) &&
			self::nonempty_string( $identity['markup5everRcdomVersion'] ) && self::matches( $identity['markup5everRcdomChecksum'], '/^[0-9a-f]{64}$/' ) &&
			self::nonempty_string( $identity['rustToolchain'] ) && self::matches( $identity['cargoLockSha256'], '/^[0-9a-f]{64}$/' ) &&
			self::matches( $identity['buildIdentity'], '/^[0-9a-f]{64}$/' ) && is_array( $build ) && self::exact_keys( $build, $build_keys ) &&
			1 === $build['schemaVersion'] && 'html-api-fuzz-html5ever-build' === $build['kind'] && 'manifest-last-v1' === $build['publicationProtocol'] &&
			self::matches( $build['cargoTomlSha256'], '/^[0-9a-f]{64}$/' ) && $identity['cargoLockSha256'] === $build['cargoLockSha256'] &&
			self::matches( $build['rustToolchainSha256'], '/^[0-9a-f]{64}$/' ) && self::matches( $build['sourceSha256'], '/^[0-9a-f]{64}$/' ) &&
			self::nonempty_string( $build['rustc'] ) && self::nonempty_string( $build['cargo'] ) && self::package_identity_valid( $build['html5ever'] ) &&
			self::package_identity_valid( $build['markup5everRcdom'] ) && $identity['html5everVersion'] === $build['html5ever']['version'] &&
			$identity['html5everChecksum'] === $build['html5ever']['checksum'] && $identity['markup5everRcdomVersion'] === $build['markup5everRcdom']['version'] &&
			$identity['markup5everRcdomChecksum'] === $build['markup5everRcdom']['checksum']
			? null : 'html5ever identity is invalid';
	}

	private static function cargo_lock_package( string $lock, string $name ): array {
		$count = preg_match_all( '/\[\[package\]\]\s*(.*?)(?=\n\[\[package\]\]|\z)/s', $lock, $packages );
		if ( false === $count || 0 === $count ) {
			throw new \RuntimeException( 'Cargo.lock package records are malformed.' );
		}
		foreach ( $packages[1] as $package ) {
			if ( 1 !== preg_match( '/^name\s*=\s*"([^"]+)"/m', $package, $package_name ) || $name !== $package_name[1] ) {
				continue;
			}
			preg_match( '/^version\s*=\s*"([^"]+)"/m', $package, $version );
			preg_match( '/^checksum\s*=\s*"([^"]+)"/m', $package, $checksum );
			if ( ! isset( $version[1], $checksum[1] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $checksum[1] ) ) {
				throw new \RuntimeException( "Cargo.lock identity for {$name} is incomplete." );
			}
			return array( 'version' => $version[1], 'checksum' => $checksum[1] );
		}
		throw new \RuntimeException( "Cargo.lock does not contain {$name}." );
	}

	private static function rust_toolchain_channel( string $toolchain ): string {
		if ( 1 !== preg_match( '/^channel\s*=\s*"([^"]+)"/m', $toolchain, $matches ) ) {
			throw new \RuntimeException( 'rust-toolchain.toml channel is missing.' );
		}
		return $matches[1];
	}

	private static function package_identity_valid( $package ): bool {
		return is_array( $package ) && self::exact_keys( $package, array( 'version', 'checksum' ) ) &&
			self::nonempty_string( $package['version'] ) && self::matches( $package['checksum'], '/^[0-9a-f]{64}$/' );
	}

	private static function positive_limit( array $limits, string $key, int $default ): int {
		$value = $limits[ $key ] ?? $default;
		if ( ! is_int( $value ) || $value < 1 ) {
			throw new \InvalidArgumentException( "{$key} must be a positive integer." );
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

	private static function nonempty_string( $value ): bool {
		return is_string( $value ) && '' !== $value;
	}

	private static function matches( $value, string $pattern ): bool {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}
}
