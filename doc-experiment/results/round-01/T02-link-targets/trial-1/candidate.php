<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'A' ) ) {
		// get_attribute() returns null when the attribute is absent.
		// It returns true for boolean/valueless attributes like <a href>,
		// and a string (possibly empty) for attributes with a value.
		if ( null !== $processor->get_attribute( 'href' ) ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}

	return $processor->get_updated_html();
}
