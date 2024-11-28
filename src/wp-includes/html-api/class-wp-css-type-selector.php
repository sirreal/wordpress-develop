<?php

final class WP_CSS_Type_Selector implements WP_CSS_HTML_Tag_Processor_Matcher {
	public function matches( WP_HTML_Tag_Processor $processor ): bool {
		$tag_name = $processor->get_tag();
		if ( null === $tag_name ) {
			return false;
		}
		if ( '*' === $this->ident ) {
			return true;
		}
		return 0 === strcasecmp( $tag_name, $this->ident );
	}

	/**
	 * @var string
	 *
	 * The type identifier string or '*'.
	 */
	public $ident;

	public function __construct( string $ident ) {
		$this->ident = $ident;
	}
}
