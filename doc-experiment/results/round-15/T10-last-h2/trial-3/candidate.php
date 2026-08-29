<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all H2 tags and update the bookmark each time we find one
	// This way, the 'last-h2' bookmark will always point to the last H2 found
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found at least one H2, seek back to it and add the class
	if ( $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
