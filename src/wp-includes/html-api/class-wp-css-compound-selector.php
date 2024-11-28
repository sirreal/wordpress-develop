<?php

/**
 * This corresponds to <compound-selector> in the grammar.
 *
 * > <compound-selector> = [ <type-selector>? <subclass-selector>* ]!
 */
final class WP_CSS_Compound_Selector implements WP_CSS_HTML_Tag_Processor_Matcher {
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		if ( $this->type_selector ) {
			if ( ! $this->type_selector->matches( $processor ) ) {
				return false;
			}
		}
		if ( null !== $this->subclass_selectors ) {
			foreach ( $this->subclass_selectors as $subclass_selector ) {
				if ( ! $subclass_selector->matches( $processor ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @var WP_CSS_Type_Selector|null */
	public $type_selector;

	/** @var array<WP_CSS_ID_Selector|WP_CSS_Class_Selector|WP_CSS_Attribute_Selector>|null */
	public $subclass_selectors;

	/**
	 * @param WP_CSS_Type_Selector|null $type_selector
	 * @param array<WP_CSS_ID_Selector|WP_CSS_Class_Selector|WP_CSS_Attribute_Selector> $subclass_selectors
	 */
	public function __construct( ?WP_CSS_Type_Selector $type_selector, array $subclass_selectors ) {
		$this->type_selector      = $type_selector;
		$this->subclass_selectors = array() === $subclass_selectors ? null : $subclass_selectors;
	}
}
