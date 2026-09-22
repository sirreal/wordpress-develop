<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$last_h2_bookmark = null;
	
	// Find all H2 tags and keep track of the last one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Release the previous bookmark if it exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark at the current H2 tag
		$last_h2_bookmark = 'last_h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found an H2, seek back to it and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
