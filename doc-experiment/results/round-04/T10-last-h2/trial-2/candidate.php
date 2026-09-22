<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag found with a bookmark
	$last_h2_bookmark = null;
	
	// Scan through the entire HTML document to find all H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip H2 tags that are inside comments
		// The processor's linear scan naturally processes comments as non-tag tokens,
		// so tags found via next_tag() won't be inside comments
		
		// Set a bookmark at this H2 tag, updating the previous bookmark reference
		$last_h2_bookmark = '__last_h2_bookmark';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found at least one H2 tag, seek to the last one and add the class
	if ( $last_h2_bookmark !== null && $processor->has_bookmark( $last_h2_bookmark ) ) {
		if ( $processor->seek( $last_h2_bookmark ) ) {
			$processor->add_class( 'final-section' );
		}
	}
	
	// Return the modified HTML
	return $processor->get_updated_html();
}
