<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag found
	$last_h2_bookmark = null;
	
	// Scan through all tags looking for H2 elements
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Release the previous bookmark if one exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark for this H2
		$last_h2_bookmark = 'last_h2_' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found at least one H2, seek to it and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
