<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all H2 tags and set a bookmark on the last one found
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Re-setting the same bookmark name moves it to the current location
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found an H2 tag, seek to it and add the class
	if ( $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	return $processor->get_updated_html();
}
