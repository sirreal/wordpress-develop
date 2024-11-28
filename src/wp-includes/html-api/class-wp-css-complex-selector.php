<?php

/**
 * This corresponds to <complex-selector> in the grammar.
 *
 * > <complex-selector> = <compound-selector> [ <combinator>? <compound-selector> ] *
 */
final class WP_CSS_Complex_Selector implements WP_CSS_HTML_Processor_Matcher {
	public function matches( WP_HTML_Processor $processor ): bool {
		// First selector must match this location.
		if ( ! $this->selectors[0]->matches( $processor ) ) {
			return false;
		}

		if ( count( $this->selectors ) === 1 ) {
			return true;
		}

		/** @var string[] */
		$breadcrumbs = array_slice( array_reverse( $processor->get_breadcrumbs() ), 1 );
		$selectors   = array_slice( $this->selectors, 1 );
		return $this->explore_matches( $selectors, $breadcrumbs );
	}

	/**
	 * This only looks at breadcrumbs and can therefore only support type selectors.
	 *
	 * @param array<WP_CSS_Compound_Selector|self::COMBINATOR_*> $selectors
	 * @param string[] $breadcrumbs
	 */
	private function explore_matches( array $selectors, array $breadcrumbs ): bool {
		if ( array() === $selectors ) {
			return true;
		}
		if ( array() === $breadcrumbs ) {
			return false;
		}

		/** @var self::COMBINATOR_* */
		$combinator = $selectors[0];
		/** @var WP_CSS_Compound_Selector */
		$selector = $selectors[1];

		switch ( $combinator ) {
			case self::COMBINATOR_CHILD:
				if ( '*' === $selector->type_selector->ident || strcasecmp( $breadcrumbs[0], $selector->type_selector->ident ) === 0 ) {
					return $this->explore_matches( array_slice( $selectors, 2 ), array_slice( $breadcrumbs, 1 ) );
				}
				return false;

			case self::COMBINATOR_DESCENDANT:
				// Find _all_ the breadcrumbs that match and recurse from each of them.
				for ( $i = 0; $i < count( $breadcrumbs ); $i++ ) {
					if ( '*' === $selector->type_selector->ident || strcasecmp( $breadcrumbs[ $i ], $selector->type_selector->ident ) === 0 ) {
						$next_crumbs = array_slice( $breadcrumbs, $i + 1 );
						if ( $this->explore_matches( array_slice( $selectors, 2 ), $next_crumbs ) ) {
							return true;
						}
					}
				}
				return false;

			default:
				throw new Exception( "Combinator '{$combinator}' is not supported yet." );
		}
	}

	const COMBINATOR_CHILD              = '>';
	const COMBINATOR_DESCENDANT         = ' ';
	const COMBINATOR_NEXT_SIBLING       = '+';
	const COMBINATOR_SUBSEQUENT_SIBLING = '~';

	/**
	 * even indexes are WP_CSS_Compound_Selector, odd indexes are string combinators.
	 * In reverse order to match the current element and then work up the tree.
	 * Any non-final selector is a type selector.
	 *
	 * @var array<WP_CSS_Compound_Selector|self::COMBINATOR_*>
	 */
	public $selectors = array();

	/**
	 * @param array<WP_CSS_Compound_Selector|self::COMBINATOR_*> $selectors
	 */
	public function __construct( array $selectors ) {
		$this->selectors = array_reverse( $selectors );
	}
}
