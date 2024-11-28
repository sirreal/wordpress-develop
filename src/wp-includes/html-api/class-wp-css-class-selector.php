<?php

final class WP_CSS_Class_Selector implements WP_CSS_HTML_Tag_Processor_Matcher {
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		return (bool) $processor->has_class( $this->ident );
	}

	/** @var string */
	public $ident;

	public function __construct( string $ident ) {
		$this->ident = $ident;
	}
}
