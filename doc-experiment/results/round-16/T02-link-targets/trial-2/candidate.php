<?php

function add_link_targets( string $html ): string {
	// Create a tag processor for the HTML document
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Loop through all A tags
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if attribute not present, string or true otherwise
		$href = $processor->get_attribute( 'href' );
		
		// If href attribute exists (even if empty), set target to _blank
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
