<?php
namespace HtmlDecoderFuzz;

/**
 * Deterministic, seed-addressed pseudo-random byte source.
 */
class Prng {
	private string $seed;
	private int $counter = 0;
	private string $buffer = '';

	public function __construct( string $seed ) {
		$this->seed = $seed;
	}

	public function bytes( int $length ): string {
		while ( strlen( $this->buffer ) < $length ) {
			$this->buffer .= hash( 'sha256', $this->seed . ':' . $this->counter++, true );
		}

		$out          = substr( $this->buffer, 0, $length );
		$this->buffer = (string) substr( $this->buffer, $length );
		return $out;
	}

	public function uint32(): int {
		$parts = unpack( 'Nvalue', $this->bytes( 4 ) );
		return (int) $parts['value'];
	}

	public function int( int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}

		return $min + ( $this->uint32() % ( $max - $min + 1 ) );
	}

	public function chance( int $numerator, int $denominator = 100 ): bool {
		return $this->int( 1, $denominator ) <= $numerator;
	}

	public function choice( array $values ) {
		return $values[ $this->int( 0, count( $values ) - 1 ) ];
	}

	/**
	 * @param array<string, int> $weights Map of value to integer weight.
	 */
	public function weighted( array $weights ) {
		$total = (int) array_sum( $weights );
		$pick  = $this->int( 1, max( 1, $total ) );
		foreach ( $weights as $value => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return $value;
			}
		}

		return array_key_first( $weights );
	}

	public function biased_length( int $max ): int {
		$bucket = $this->weighted(
			array(
				'tiny'  => 38,
				'short' => 38,
				'mid'   => 20,
				'large' => 4,
			)
		);

		switch ( $bucket ) {
			case 'tiny':
				return $this->int( 0, min( 8, $max ) );
			case 'short':
				return $this->int( 0, min( 64, $max ) );
			case 'mid':
				return $this->int( 0, min( 1024, $max ) );
			default:
				return $this->int( 0, $max );
		}
	}
}
