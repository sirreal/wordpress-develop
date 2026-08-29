<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tags and track the last H2
	while ( $processor->next_tag( 'h2' ) ) {
		// Set a bookmark at each H2 to remember the last one we see
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found at least one H2, seek to it and add the class
	if ( $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
