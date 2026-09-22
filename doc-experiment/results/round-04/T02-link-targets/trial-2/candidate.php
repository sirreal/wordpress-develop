<?php

function add_link_targets( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Check if the A tag has an href attribute
		// get_attribute returns null if not present, true if present without value, or a string value
		$href = $processor->get_attribute( 'href' );
		
		// href attribute is present if it's not null (includes empty string and boolean true)
		if ( null !== $href ) {
			// Set the target attribute to _blank
			$processor->set_attribute( 'target', '_blank' );
		}
	}
	
	return $processor->get_updated_html();
}
