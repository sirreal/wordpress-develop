<?php
namespace HtmlApiFuzz;

/**
 * Deterministic byte- and markup-level mutations over corpus inputs. All
 * randomness flows through the provided Prng, so a (seed, corpus) pair always
 * produces the same mutated input.
 */
class Mutator {
	private const INTERESTING_BYTES = array( '<', '>', '&', '"', "'", '=', '/', '!', '-', ' ', "\n", "\r", "\t", "\f", "\0", ';', '`' );
	private const SWAP_TAG_NAMES    = array( 'select', 'option', 'table', 'td', 'tr', 'template', 'b', 'a', 'p', 'div', 'svg', 'math', 'title', 'textarea', 'script', 'style', 'plaintext', 'noframes', 'body', 'html', 'head', 'frameset' );

	/**
	 * Applies 1-4 mutations to the input and reports which operations ran.
	 *
	 * @return array{input: string, operations: string[]}
	 */
	public static function mutate( string $input, Prng $rng, array $corpus_entries = array() ): array {
		$operations = array();
		$rounds     = $rng->int( 1, 4 );
		for ( $i = 0; $i < $rounds; $i++ ) {
			$operation = $rng->weighted(
				array(
					'insert-byte'    => 18,
					'delete-chunk'   => 18,
					'duplicate-chunk' => 12,
					'replace-byte'   => 16,
					'swap-tag-name'  => 14,
					'toggle-case'    => 8,
					'splice-corpus'  => 14,
				)
			);

			$mutated = self::apply( $operation, $input, $rng, $corpus_entries );
			if ( null !== $mutated ) {
				$input        = $mutated;
				$operations[] = $operation;
			}
		}

		return array(
			'input'      => $input,
			'operations' => $operations,
		);
	}

	private static function apply( string $operation, string $input, Prng $rng, array $corpus_entries ): ?string {
		$length = strlen( $input );

		switch ( $operation ) {
			case 'insert-byte':
				$at = $length > 0 ? $rng->int( 0, $length ) : 0;
				return substr( $input, 0, $at ) . $rng->choice( self::INTERESTING_BYTES ) . substr( $input, $at );

			case 'delete-chunk':
				if ( $length < 2 ) {
					return null;
				}
				$at  = $rng->int( 0, $length - 1 );
				$len = $rng->int( 1, max( 1, min( 32, $length - $at ) ) );
				return substr( $input, 0, $at ) . substr( $input, $at + $len );

			case 'duplicate-chunk':
				if ( $length < 1 ) {
					return null;
				}
				$at  = $rng->int( 0, $length - 1 );
				$len = $rng->int( 1, max( 1, min( 24, $length - $at ) ) );
				$chunk = substr( $input, $at, $len );
				return substr( $input, 0, $at + $len ) . $chunk . substr( $input, $at + $len );

			case 'replace-byte':
				if ( $length < 1 ) {
					return null;
				}
				$at = $rng->int( 0, $length - 1 );
				return substr( $input, 0, $at ) . $rng->choice( self::INTERESTING_BYTES ) . substr( $input, $at + 1 );

			case 'swap-tag-name':
				if ( ! preg_match_all( '/<\/?([a-zA-Z][a-zA-Z0-9-]*)/', $input, $m, PREG_OFFSET_CAPTURE ) ) {
					return null;
				}
				$pick    = $m[1][ $rng->int( 0, count( $m[1] ) - 1 ) ];
				$replace = $rng->choice( self::SWAP_TAG_NAMES );
				return substr( $input, 0, $pick[1] ) . $replace . substr( $input, $pick[1] + strlen( $pick[0] ) );

			case 'toggle-case':
				if ( $length < 1 ) {
					return null;
				}
				$at   = $rng->int( 0, $length - 1 );
				$len  = $rng->int( 1, max( 1, min( 16, $length - $at ) ) );
				$head = substr( $input, $at, $len );
				$head = $rng->chance( 50 ) ? strtoupper( $head ) : strtolower( $head );
				return substr( $input, 0, $at ) . $head . substr( $input, $at + $len );

			case 'splice-corpus':
				if ( array() === $corpus_entries ) {
					return null;
				}
				$other = $corpus_entries[ $rng->int( 0, count( $corpus_entries ) - 1 ) ]['data'];
				$at    = $length > 0 ? $rng->int( 0, $length ) : 0;
				$from  = strlen( $other ) > 0 ? $rng->int( 0, strlen( $other ) - 1 ) : 0;
				$len   = $rng->int( 1, max( 1, min( 96, strlen( $other ) - $from ) ) );
				return substr( $input, 0, $at ) . substr( $other, $from, $len ) . substr( $input, $at );

			default:
				return null;
		}
	}
}
