<?php

function remove_external_class( string $html ): string {
	// Create a Tag Processor to scan and modify the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Iterate through all 'A' tags in the document
	while ( $processor->next_tag( 'A' ) ) {
		// Remove the 'external' class from each A tag
		// remove_class is safe to call even if the class doesn't exist
		$processor->remove_class( 'external' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
