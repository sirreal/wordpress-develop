<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;
	
	// Scan through all H2 tags and remember the last one
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Skip tag closers, only process openers
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set/update the bookmark to track the last H2 found
		$processor->set_bookmark( 'last-h2' );
		$last_h2_bookmark = true;
	}
	
	// If we found an H2, seek back to it and add the class
	if ( $last_h2_bookmark && $processor->seek( 'last-h2' ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	return $processor->get_updated_html();
}
