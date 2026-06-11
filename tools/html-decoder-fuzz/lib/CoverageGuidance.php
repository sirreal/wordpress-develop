<?php
namespace HtmlDecoderFuzz;

/**
 * Captures target coverage and retains payloads that discover new edges.
 */
class CoverageGuidance {
	/** @var array<string, true> */
	private array $seen_edges = array();

	/** @var string[] */
	private array $target_files;

	/** @var array<string, true> */
	private array $target_file_set;

	private string $provider;

	public function __construct() {
		$this->target_files    = self::target_files();
		$this->target_file_set = array_fill_keys( $this->target_files, true );
		$this->provider        = self::fake_enabled() ? 'fake' : 'pcov';
	}

	public static function available(): bool {
		return self::fake_enabled() || self::pcov_available();
	}

	public static function unavailable_reason(): string {
		if ( getenv( 'HTML_DECODER_FUZZ_DISABLE_PCOV' ) ) {
			return 'coverage mode requires pcov; pcov was disabled by HTML_DECODER_FUZZ_DISABLE_PCOV';
		}
		if ( ! extension_loaded( 'pcov' ) ) {
			return 'coverage mode requires the pcov extension';
		}
		if ( '0' === (string) ini_get( 'pcov.enabled' ) ) {
			return 'coverage mode requires pcov.enabled=1';
		}
		if ( ! function_exists( 'pcov\\start' ) || ! function_exists( 'pcov\\stop' ) || ! function_exists( 'pcov\\collect' ) || ! function_exists( 'pcov\\clear' ) ) {
			return 'coverage mode requires the pcov start, stop, collect, and clear functions';
		}

		return 'coverage mode is unavailable';
	}

	public function provider(): string {
		return $this->provider;
	}

	public function begin_case(): void {
		if ( 'pcov' !== $this->provider ) {
			return;
		}

		\pcov\stop();
		\pcov\clear();
		\pcov\start();
	}

	/**
	 * @return array<int, array{key: string, file: string, line: int, hits: int}>
	 */
	public function finish_case( string $payload, string $context, string $strategy ): array {
		if ( 'fake' === $this->provider ) {
			return $this->fake_edges( $payload, $context, $strategy );
		}

		\pcov\stop();
		$type     = defined( 'pcov\\inclusive' ) ? constant( 'pcov\\inclusive' ) : 1;
		$coverage = \pcov\collect( $type, $this->target_files );
		\pcov\clear();

		return $this->normalize_coverage( $coverage );
	}

	/**
	 * @param array<int, array{key: string, file: string, line: int, hits: int}> $edges
	 * @return array<int, array{key: string, file: string, line: int, hits: int}>
	 */
	public function new_edges( array $edges ): array {
		$new_edges = array();
		foreach ( $edges as $edge ) {
			if ( isset( $this->seen_edges[ $edge['key'] ] ) ) {
				continue;
			}
			$this->seen_edges[ $edge['key'] ] = true;
			$new_edges[] = $edge;
		}

		return $new_edges;
	}

	public function seen_edge_count(): int {
		return count( $this->seen_edges );
	}

	/**
	 * @param array{context: string, strategy: string, payload: string} $generated
	 * @param array<int, array{key: string, file: string, line: int, hits: int}> $new_edges
	 * @return array{artifact_dir: ?string, artifact_retained: bool, artifact_reused: bool}
	 */
	public function retain_payload( string $output_dir, string $seed, int $case, array $generated, string $payload, array $new_edges ): array {
		if ( '' === $output_dir ) {
			return array(
				'artifact_dir'      => null,
				'artifact_retained' => false,
				'artifact_reused'   => false,
			);
		}

		$coverage_dir = rtrim( $output_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'coverage-corpus';
		if ( is_link( $coverage_dir ) || ( file_exists( $coverage_dir ) && ! is_dir( $coverage_dir ) ) ) {
			throw new \RuntimeException( "coverage corpus path is not a directory: {$coverage_dir}" );
		}
		if ( ! is_dir( $coverage_dir ) && ! mkdir( $coverage_dir, 0777, true ) && ! is_dir( $coverage_dir ) ) {
			throw new \RuntimeException( "cannot create coverage corpus dir {$coverage_dir}" );
		}

		$payload_hash = hash( 'sha256', $payload );
		$case_dir     = sprintf(
			'%s/payload-seed%s-case%d-%s',
			$coverage_dir,
			preg_replace( '/[^A-Za-z0-9_-]/', '_', $seed ),
			$case,
			substr( $payload_hash, 0, 16 )
		);
		if ( is_link( $case_dir ) || ( file_exists( $case_dir ) && ! is_dir( $case_dir ) ) ) {
			throw new \RuntimeException( "coverage corpus artifact path is not a directory: {$case_dir}" );
		}

		$artifact_reused = is_dir( $case_dir );
		if ( ! $artifact_reused && ! mkdir( $case_dir, 0777, false ) && ! is_dir( $case_dir ) ) {
			throw new \RuntimeException( "cannot create coverage corpus artifact {$case_dir}" );
		}

		if ( ! $artifact_reused ) {
			$manifest = array(
				'type'           => 'coverage',
				'seed'           => $seed,
				'case'           => $case,
				'mode'           => 'coverage',
				'context'        => $generated['context'],
				'strategy'       => $generated['strategy'],
				'input_size'     => strlen( $payload ),
				'payload_base64' => base64_encode( $payload ),
				'payload_preview' => Cli::payload_preview( $payload ),
				'coverage_provider' => $this->provider,
				'new_edge_count' => count( $new_edges ),
				'new_edges'      => $new_edges,
				'git'            => Cli::git_metadata( Bootstrap::repo_root() ),
			);
			$manifest_json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if (
				false === $manifest_json ||
				! Cli::write_file( "{$case_dir}/payload.txt", $payload ) ||
				! Cli::write_file( "{$case_dir}/coverage.json", $manifest_json )
			) {
				throw new \RuntimeException( "cannot write coverage corpus artifact under {$case_dir}" );
			}
		}

		return array(
			'artifact_dir'      => $case_dir,
			'artifact_retained' => ! $artifact_reused,
			'artifact_reused'   => $artifact_reused,
		);
	}

	/**
	 * @return string[]
	 */
	public static function target_files(): array {
		$root  = Bootstrap::repo_root();
		$files = array(
			$root . '/src/wp-includes/html-api/class-wp-html-decoder.php',
			$root . '/src/wp-includes/class-wp-token-map.php',
		);

		return array_values(
			array_filter(
				array_map(
					static function ( string $file ): ?string {
						$real = realpath( $file );
						return false === $real ? null : $real;
					},
					$files
				)
			)
		);
	}

	private static function fake_enabled(): bool {
		$value = getenv( 'HTML_DECODER_FUZZ_FAKE_COVERAGE' );
		return false !== $value && '' !== $value && '0' !== $value;
	}

	private static function pcov_available(): bool {
		return (
			! getenv( 'HTML_DECODER_FUZZ_DISABLE_PCOV' ) &&
			extension_loaded( 'pcov' ) &&
			'0' !== (string) ini_get( 'pcov.enabled' ) &&
			function_exists( 'pcov\\start' ) &&
			function_exists( 'pcov\\stop' ) &&
			function_exists( 'pcov\\collect' ) &&
			function_exists( 'pcov\\clear' )
		);
	}

	/**
	 * @param mixed $coverage
	 * @return array<int, array{key: string, file: string, line: int, hits: int}>
	 */
	private function normalize_coverage( $coverage ): array {
		if ( ! is_array( $coverage ) ) {
			return array();
		}

		$edges = array();
		foreach ( $coverage as $file => $lines ) {
			$file = realpath( (string) $file ) ?: (string) $file;
			if ( ! isset( $this->target_file_set[ $file ] ) || ! is_array( $lines ) ) {
				continue;
			}

			foreach ( $lines as $line => $hits ) {
				$line = (int) $line;
				$hits = (int) $hits;
				if ( $line <= 0 || $hits <= 0 ) {
					continue;
				}
				$edge = $this->edge( $file, $line, $hits );
				$edges[ $edge['key'] ] = $edge;
			}
		}
		ksort( $edges, SORT_STRING );

		return array_values( $edges );
	}

	/**
	 * @return array<int, array{key: string, file: string, line: int, hits: int}>
	 */
	private function fake_edges( string $payload, string $context, string $strategy ): array {
		$digest = hash( 'sha256', $context . "\0" . $strategy . "\0" . $payload );
		$edges  = array();

		foreach ( $this->target_files as $index => $file ) {
			$line_count = count( file( $file, FILE_IGNORE_NEW_LINES ) ?: array() );
			$line_count = max( 1, $line_count );
			$offset     = hexdec( substr( $digest, $index * 8, 8 ) );
			$edges[]    = $this->edge( $file, 1 + ( $offset % $line_count ), 1 );
		}

		return $edges;
	}

	/**
	 * @return array{key: string, file: string, line: int, hits: int}
	 */
	private function edge( string $file, int $line, int $hits ): array {
		return array(
			'key'  => hash( 'sha256', $file . "\0" . $line ),
			'file' => $file,
			'line' => $line,
			'hits' => $hits,
		);
	}
}
