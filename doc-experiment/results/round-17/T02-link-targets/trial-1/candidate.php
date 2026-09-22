<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Iterate through all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if the attribute doesn't exist,
		// otherwise returns the attribute value (could be string or true for boolean attrs)
		$href = $processor->get_attribute( 'href' );
		
		// Only set target="_blank" if href attribute is present
		if ( null !== $href ) {
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
