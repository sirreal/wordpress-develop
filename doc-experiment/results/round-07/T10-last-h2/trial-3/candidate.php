<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;
	
	// Scan through the document to find all H2 tags and track the last one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip H2 tags inside HTML comments
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set a bookmark at this H2 tag, overwriting any previous bookmark
		// This keeps the bookmark at the last H2 found
		$last_h2_bookmark = 'last-h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found an H2, seek back to it and add the class
	if ( $last_h2_bookmark !== null && $processor->has_bookmark( $last_h2_bookmark ) ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
