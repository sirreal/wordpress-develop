<?php
namespace EncodingFuzz;

/**
 * Client for a persistent external oracle subprocess speaking the
 * length-prefixed binary protocol documented in `oracles/`.
 *
 * One subprocess handles all cases for the life of the worker, so the
 * per-case cost is a single pipe round trip.
 */
class ExternalOracle {
	public string $name;
	private array $command;
	/** @var resource|null */
	private $process = null;
	/** @var resource|null */
	private $stdin = null;
	/** @var resource|null */
	private $stdout = null;
	private ?string $last_error = null;
	private ?string $memo_input = null;
	private ?array $memo_result = null;

	public function __construct( string $name, array $command ) {
		$this->name    = $name;
		$this->command = $command;
	}

	/**
	 * @return array{0: ?self, 1: ?string} Oracle or null, plus error message.
	 */
	public static function create( string $name ): array {
		switch ( $name ) {
			case 'python3':
				$command = array( 'python3', __DIR__ . '/../oracles/oracle-python.py' );
				break;
			case 'node':
				$command = array( 'node', __DIR__ . '/../oracles/oracle-node.mjs' );
				break;
			default:
				return array( null, "unknown external oracle '{$name}'" );
		}

		$oracle = new self( $name, $command );
		$error  = $oracle->start();
		if ( null !== $error ) {
			return array( null, $error );
		}

		return array( $oracle, null );
	}

	private function start(): ?string {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'file', '/dev/null', 'a' ),
		);

		$process = @proc_open( $this->command, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return "failed to launch {$this->name} oracle";
		}

		$this->process = $process;
		$this->stdin   = $pipes[0];
		$this->stdout  = $pipes[1];

		// Probe with a trivial request so launch failures surface immediately.
		$probe = $this->check( 'ok' );
		if ( null === $probe || true !== $probe['valid'] || 'ok' !== $probe['scrubbed'] ) {
			$detail = $this->last_error ?? 'bad probe response';
			$this->shutdown();
			return "{$this->name} oracle failed startup probe: {$detail}";
		}

		return null;
	}

	public function is_alive(): bool {
		return null !== $this->process;
	}

	public function last_error(): ?string {
		return $this->last_error;
	}

	/**
	 * @return array{valid: bool, scrubbed: string}|null Null on transport failure.
	 */
	public function check( string $bytes ): ?array {
		if ( null === $this->process ) {
			return null;
		}

		// Validity and scrub oracles ask about the same input back to
		// back; answer both from one pipe round trip.
		if ( $bytes === $this->memo_input ) {
			return $this->memo_result;
		}

		$request = pack( 'N', strlen( $bytes ) ) . $bytes;
		if ( ! $this->write_exact( $request ) ) {
			$this->fail( 'write failed' );
			return null;
		}

		$header = $this->read_exact( 5 );
		if ( null === $header ) {
			$this->fail( 'short response header' );
			return null;
		}

		$valid  = "\x00" !== $header[0];
		$length = unpack( 'Nlength', substr( $header, 1 ) )['length'];

		$scrubbed = 0 === $length ? '' : $this->read_exact( $length );
		if ( null === $scrubbed ) {
			$this->fail( 'short response body' );
			return null;
		}

		$this->memo_input  = $bytes;
		$this->memo_result = array(
			'valid'    => $valid,
			'scrubbed' => $scrubbed,
		);

		return $this->memo_result;
	}

	private function write_exact( string $bytes ): bool {
		$total = strlen( $bytes );
		$sent  = 0;
		while ( $sent < $total ) {
			$written = @fwrite( $this->stdin, substr( $bytes, $sent ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$sent += $written;
		}
		return true;
	}

	private function read_exact( int $length ): ?string {
		$out = '';
		while ( strlen( $out ) < $length ) {
			$chunk = @fread( $this->stdout, $length - strlen( $out ) );
			if ( false === $chunk || '' === $chunk ) {
				return null;
			}
			$out .= $chunk;
		}
		return $out;
	}

	private function fail( string $reason ): void {
		$this->last_error = $reason;
		$this->shutdown();
	}

	public function shutdown(): void {
		if ( is_resource( $this->stdin ) ) {
			@fclose( $this->stdin );
		}
		if ( is_resource( $this->stdout ) ) {
			@fclose( $this->stdout );
		}
		if ( is_resource( $this->process ) ) {
			@proc_terminate( $this->process );
			@proc_close( $this->process );
		}
		$this->process = null;
		$this->stdin   = null;
		$this->stdout  = null;
	}
}
