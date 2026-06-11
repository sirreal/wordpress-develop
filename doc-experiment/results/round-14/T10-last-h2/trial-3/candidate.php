<?php

function mark_last_h2( string $html ): string {
	// Create a tag processor to scan through the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track whether we found at least one H2
	$found_h2 = false;
	
	// Scan through all H2 tags and use a bookmark to track the last one found
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip tag closers, only process openers
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set the bookmark to the current H2 position
		// Re-setting the same bookmark name moves it to the current location
		$processor->set_bookmark( 'last-h2' );
		$found_h2 = true;
	}
	
	// If we found an H2, seek back to it and add the class
	if ( $found_h2 && $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
	}
	
	// Release the bookmark to clean up
	if ( $found_h2 ) {
		$processor->release_bookmark( 'last-h2' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
