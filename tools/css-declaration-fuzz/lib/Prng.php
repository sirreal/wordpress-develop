<?php
namespace CssDeclarationFuzz;

/** A deterministic SHA-256-keyed byte stream. */
class Prng {
	private $key;
	private $counter = 0;
	private $buffer  = '';

	public function __construct( string $seed, string $label = '' ) {
		$this->key = $seed . "\x1f" . $label;
	}

	public function bytes( int $length ): string {
		while ( strlen( $this->buffer ) < $length ) {
			$this->buffer .= hash( 'sha256', $this->key . ':' . $this->counter++, true );
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

	/** @param array<string, int> $weights */
	public function weighted( array $weights ): string {
		$pick = $this->int( 1, max( 1, (int) array_sum( $weights ) ) );
		foreach ( $weights as $value => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return $value;
			}
		}
		return (string) array_key_first( $weights );
	}
}
