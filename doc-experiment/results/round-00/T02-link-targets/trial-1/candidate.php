<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'A' ) ) {
		// get_attribute() returns null if the attribute is absent,
		// true for boolean (value-less) attributes, or a string value.
		// Any non-null value means href is present.
		if ( null !== $processor->get_attribute( 'href' ) ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}

	return $processor->get_updated_html();
}
