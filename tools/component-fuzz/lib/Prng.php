<?php
namespace ComponentFuzz;

final class Prng {
	private int $state;

	public function __construct( int $seed ) {
		$this->state = 0 === $seed ? 0x9e3779b9 : ( $seed & 0x7fffffff );
	}

	public function next(): int {
		$x = $this->state;
		$x ^= ( $x << 13 ) & 0x7fffffff;
		$x ^= ( $x >> 17 );
		$x ^= ( $x << 5 ) & 0x7fffffff;
		$this->state = $x & 0x7fffffff;

		return $this->state;
	}

	public function int( int $min, int $max ): int {
		if ( $max < $min ) {
			throw new \InvalidArgumentException( 'Invalid PRNG range.' );
		}

		if ( $max === $min ) {
			return $min;
		}

		return $min + ( $this->next() % ( $max - $min + 1 ) );
	}

	public function bool( int $true_percent = 50 ): bool {
		return $this->int( 1, 100 ) <= $true_percent;
	}

	public function choice( array $values ) {
		if ( array() === $values ) {
			throw new \InvalidArgumentException( 'Cannot choose from an empty array.' );
		}

		return $values[ array_keys( $values )[ $this->int( 0, count( $values ) - 1 ) ] ];
	}

	public function weighted_choice( array $weighted_values ) {
		$total = 0;
		foreach ( $weighted_values as $entry ) {
			$total += max( 0, (int) $entry[0] );
		}

		if ( $total <= 0 ) {
			throw new \InvalidArgumentException( 'Weighted choice needs positive weight.' );
		}

		$pick = $this->int( 1, $total );
		foreach ( $weighted_values as $entry ) {
			$pick -= max( 0, (int) $entry[0] );
			if ( $pick <= 0 ) {
				return $entry[1];
			}
		}

		return $weighted_values[ array_key_last( $weighted_values ) ][1];
	}

	public function derive( string $label ): self {
		return new self( self::mix_seed( $this->state, $label ) );
	}

	public static function mix_seed( int $seed, string $label ): int {
		$hash = crc32( $label );
		return ( ( $seed * 1103515245 ) ^ $hash ^ 0x45d9f3b ) & 0x7fffffff;
	}
}

