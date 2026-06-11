<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all H2 tags, remembering the position of the last one
	$last_h2_bookmark = null;
	
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// If we had a previous bookmark, release it since we found a newer one
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark at this H2 tag
		$last_h2_bookmark = 'last-h2-' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found an H2, go back to it and add the class
	if ( $last_h2_bookmark !== null ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
