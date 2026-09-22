<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	$last_h2_bookmark = null;
	
	// Scan through the entire document looking for H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip if this is a closing tag
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set/update bookmark to track the last H2 we've found
		$processor->set_bookmark( 'last-h2' );
		$last_h2_bookmark = 'last-h2';
	}
	
	// If we found at least one H2 tag, seek back to it and add the class
	if ( $last_h2_bookmark && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
