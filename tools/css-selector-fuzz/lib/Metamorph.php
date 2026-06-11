<?php
namespace CssSelectorFuzz;

/**
 * Metamorphic transforms over the canonical selector AST.
 *
 * Each transform produces a variant selector that must select exactly the
 * same elements as the original — no external oracle needed, the original's
 * own match set is the expectation. AST-preserving transforms additionally
 * assert that parsing the variant yields the transformed AST (so escape,
 * whitespace, and case randomization cannot silently change meaning).
 *
 * Transforms:
 *  - rerender:       same AST, fresh whitespace/quoting randomness with
 *                    aggressive no-op ident escapes.
 *  - typecase:       ASCII-random-case every type selector name (type
 *                    matching is case-insensitive in HTML documents).
 *  - subs-reorder:   rotate the subclass selectors of each compound with two
 *                    or more (subclass order is commutative).
 *  - universal:      write the omitted type selector as an explicit `*`.
 *  - dup-branch:     append a duplicate of one selector-list branch
 *                    (a, a ≡ a).
 */
class Metamorph {

	/**
	 * @param array $list_ast Canonical complex-list AST of the original.
	 * @param Prng  $prng     Variant rendering randomness.
	 * @return array<int, array{
	 *     name: string,
	 *     selector: string,
	 *     ast: array,
	 *     astMustMatch: bool,
	 * }>
	 */
	public static function variants( array $list_ast, Prng $prng ): array {
		/*
		 * from_selectors() scrubs invalid UTF-8 to U+FFFD before parsing, so
		 * parsed AST names are always valid UTF-8 and this guard should be
		 * unreachable. It stays as defense in depth: the renderer can only
		 * round-trip valid UTF-8 names, and a future AST source that skips
		 * normalization would otherwise corrupt the variants silently.
		 */
		if ( ! ast_strings_are_utf8( $list_ast ) ) {
			return array();
		}

		$out = array();

		$out[] = array(
			'name'         => 'rerender',
			'selector'     => SelectorGenerator::render( $prng->fork( 'rerender' ), $list_ast, true ),
			'ast'          => $list_ast,
			'astMustMatch' => true,
		);

		$typecase = self::map_types(
			$list_ast,
			static function ( string $type ) use ( $prng ): string {
				if ( '*' === $type ) {
					return $type;
				}
				$out = '';
				for ( $i = 0; $i < strlen( $type ); $i++ ) {
					$c    = $type[ $i ];
					$out .= $prng->chance( 50 ) ? strtoupper( $c ) : strtolower( $c );
				}
				return $out;
			}
		);
		if ( $typecase !== $list_ast ) {
			$out[] = array(
				'name'         => 'typecase',
				'selector'     => SelectorGenerator::render( $prng->fork( 'typecase' ), $typecase ),
				'ast'          => $typecase,
				'astMustMatch' => true,
			);
		}

		$reordered = self::rotate_subs( $list_ast );
		if ( $reordered !== $list_ast ) {
			$out[] = array(
				'name'         => 'subs-reorder',
				'selector'     => SelectorGenerator::render( $prng->fork( 'subs-reorder' ), $reordered ),
				'ast'          => $reordered,
				'astMustMatch' => true,
			);
		}

		$universal = self::explicit_universal( $list_ast );
		if ( $universal !== $list_ast ) {
			$out[] = array(
				'name'         => 'universal',
				'selector'     => SelectorGenerator::render( $prng->fork( 'universal' ), $universal ),
				'ast'          => $universal,
				'astMustMatch' => true,
			);
		}

		$duplicated   = $list_ast;
		$duplicated[] = $list_ast[ $prng->int( 0, count( $list_ast ) - 1 ) ];
		$out[]        = array(
			'name'         => 'dup-branch',
			'selector'     => SelectorGenerator::render( $prng->fork( 'dup-branch' ), $duplicated ),
			'ast'          => $duplicated,
			'astMustMatch' => true,
		);

		return $out;
	}

	/** Applies $fn to every type-selector name: compound types and context types. */
	private static function map_types( array $list_ast, callable $fn ): array {
		foreach ( $list_ast as &$complex ) {
			foreach ( $complex['context'] as &$pair ) {
				$pair[0] = $fn( $pair[0] );
			}
			unset( $pair );
			if ( null !== $complex['self']['type'] ) {
				$complex['self']['type'] = $fn( $complex['self']['type'] );
			}
		}
		unset( $complex );
		return $list_ast;
	}

	/** Rotates the subclass list of every compound that has two or more. */
	private static function rotate_subs( array $list_ast ): array {
		foreach ( $list_ast as &$complex ) {
			$subs = $complex['self']['subs'];
			if ( is_array( $subs ) && count( $subs ) >= 2 ) {
				$subs[]                  = array_shift( $subs );
				$complex['self']['subs'] = $subs;
			}
		}
		unset( $complex );
		return $list_ast;
	}

	/** Writes an explicit `*` wherever a compound omitted its type selector. */
	private static function explicit_universal( array $list_ast ): array {
		foreach ( $list_ast as &$complex ) {
			if ( null === $complex['self']['type'] && null !== $complex['self']['subs'] ) {
				$complex['self']['type'] = '*';
			}
		}
		unset( $complex );
		return $list_ast;
	}
}
