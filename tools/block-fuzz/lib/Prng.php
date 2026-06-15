<?php
/**
 * Deterministic pseudo-random number generator for the block fuzzer.
 *
 * @package WordPress
 * @subpackage Block_Fuzz
 */

namespace BlockFuzz;

/**
 * SHA-256 based PRNG so seed N always produces the same document.
 */
class Prng {
	/**
	 * Seed material.
	 *
	 * @var string
	 */
	private $seed;

	/**
	 * Hash counter.
	 *
	 * @var int
	 */
	private $counter = 0;

	/**
	 * Unconsumed random bytes.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Constructor.
	 *
	 * @param int|string $seed Seed material.
	 */
	public function __construct( $seed ) {
		$this->seed = (string) $seed;
	}

	/**
	 * Returns deterministic random bytes.
	 *
	 * @param int $length Number of bytes.
	 * @return string Random bytes.
	 */
	public function bytes( $length ) {
		while ( strlen( $this->buffer ) < $length ) {
			$this->buffer .= hash( 'sha256', $this->seed . ':' . $this->counter++, true );
		}

		$out          = substr( $this->buffer, 0, $length );
		$this->buffer = substr( $this->buffer, $length );
		return $out;
	}

	/**
	 * Returns a deterministic unsigned 32-bit integer.
	 *
	 * @return int Unsigned 32-bit integer.
	 */
	public function uint32() {
		$parts = unpack( 'Nvalue', $this->bytes( 4 ) );
		return (int) $parts['value'];
	}

	/**
	 * Returns a deterministic integer in an inclusive range.
	 *
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Random value.
	 */
	public function int( $min, $max ) {
		if ( $max <= $min ) {
			return $min;
		}

		return $min + ( $this->uint32() % ( $max - $min + 1 ) );
	}

	/**
	 * Returns whether a chance fires.
	 *
	 * @param int $numerator   Numerator.
	 * @param int $denominator Denominator.
	 * @return bool Whether the chance fired.
	 */
	public function chance( $numerator, $denominator = 100 ) {
		return $this->int( 1, $denominator ) <= $numerator;
	}

	/**
	 * Picks one value from a list.
	 *
	 * @param array $values Values.
	 * @return mixed Picked value.
	 */
	public function choice( $values ) {
		return $values[ $this->int( 0, count( $values ) - 1 ) ];
	}

	/**
	 * Picks one array key according to integer weights.
	 *
	 * @param array $weights Map of value => weight.
	 * @return string|int Picked key.
	 */
	public function weighted( $weights ) {
		$total = array_sum( $weights );
		$pick  = $this->int( 1, max( 1, (int) $total ) );

		foreach ( $weights as $value => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return $value;
			}
		}

		return key( $weights );
	}
}
