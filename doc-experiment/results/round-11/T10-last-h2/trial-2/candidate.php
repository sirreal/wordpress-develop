<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all H2 tags and keep track of the last one found
	while ( $processor->next_tag( 'h2' ) ) {
		// Set a bookmark on each H2 tag found
		// This will overwrite the previous bookmark, effectively tracking the last H2
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found an H2 tag, seek to it and add the class
	if ( $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
