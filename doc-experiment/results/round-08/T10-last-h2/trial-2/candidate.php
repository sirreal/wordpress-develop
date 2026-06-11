<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$last_h2_bookmark = null;
	
	// Scan through the document and track the last H2 tag found
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Release the previous bookmark if we have one
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Create a new bookmark for this H2
		$last_h2_bookmark = 'last_h2_found';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found at least one H2, seek back to it and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
