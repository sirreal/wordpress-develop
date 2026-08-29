<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag by setting and updating a bookmark
	$last_h2_found = false;
	
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip if this is a closing tag - we only care about opening tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		$last_h2_found = true;
		// Update the bookmark to point to the current H2 tag
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found an H2, seek back to it and add the class
	if ( $last_h2_found && $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	return $processor->get_updated_html();
}
