<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Loop through all tags looking for A tags
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if this A tag has an href attribute
		// get_attribute() returns null if attribute doesn't exist
		// It returns "" (empty string) or true for boolean attributes, and strings for valued attributes
		$href = $processor->get_attribute( 'href' );
		
		if ( $href !== null ) {
			// The href attribute exists (even if empty), so set target="_blank"
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	// Return the updated HTML with all modifications applied
	return $processor->get_updated_html();
}
