<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if attribute doesn't exist
		// It returns true for boolean attributes, or a string for valued attributes
		// An href attribute counts as present even if empty (href="" or <a href>)
		if ( null !== $processor->get_attribute( 'href' ) ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
