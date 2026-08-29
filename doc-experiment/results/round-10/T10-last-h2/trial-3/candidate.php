<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag found
	$last_h2_found = false;
	
	// Scan through all H2 tags
	while ( $processor->next_tag( 'h2' ) ) {
		$last_h2_found = true;
		// Set bookmark on each H2, moving the bookmark to track the latest one
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found an H2, seek to it and add the class
	if ( $last_h2_found && $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
	}
	
	// Return the updated HTML
	return $processor->get_updated_html();
}
