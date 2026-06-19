<?php
namespace HtmlApiFuzz;

class Prng {
	private $seed;
	private $counter = 0;
	private $buffer  = '';

	public function __construct( $seed ) {
		$this->seed = (string) $seed;
	}

	public function bytes( int $length ): string {
		while ( strlen( $this->buffer ) < $length ) {
			$this->buffer .= hash( 'sha256', $this->seed . ':' . $this->counter++, true );
		}

		$out          = substr( $this->buffer, 0, $length );
		$this->buffer = substr( $this->buffer, $length );
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

	public function weighted( array $weights ) {
		$total = array_sum( $weights );
		$pick  = $this->int( 1, max( 1, (int) $total ) );
		foreach ( $weights as $value => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return $value;
			}
		}

		return array_key_first( $weights );
	}
}

