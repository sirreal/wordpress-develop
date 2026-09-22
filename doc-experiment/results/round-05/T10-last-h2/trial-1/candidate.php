<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track if we've found any H2 tags
	$last_h2_bookmark = null;
	
	// Scan through all tags looking for H2
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Release the previous bookmark if it exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark on this H2 tag
		$last_h2_bookmark = 'last_h2_' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found at least one H2, seek to the last one and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
