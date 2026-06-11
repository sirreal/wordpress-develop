<?php

function mark_last_h2( string $html ): string {
	// Create a tag processor for the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag found
	$last_h2_bookmark = null;
	
	// Scan through all tags looking for H2 tags
	while ( $processor->next_tag( 'h2' ) ) {
		// Release the previous bookmark if we have one
		if ( $last_h2_bookmark ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark at this H2 tag
		$last_h2_bookmark = 'last_h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found an H2 tag, seek back to it and add the class
	if ( $last_h2_bookmark && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
