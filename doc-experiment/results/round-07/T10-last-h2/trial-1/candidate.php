<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track if we found any H2 tags
	$found_h2 = false;
	
	// Scan through all H2 tags, moving the bookmark to each one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$found_h2 = true;
		// Set/move the bookmark to the current H2
		$processor->set_bookmark( 'last_h2' );
	}
	
	// If we found at least one H2, seek back to the last one and add the class
	if ( $found_h2 && $processor->has_bookmark( 'last_h2' ) ) {
		$processor->seek( 'last_h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last_h2' );
	}
	
	return $processor->get_updated_html();
}
