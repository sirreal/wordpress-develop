<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	$last_h2_bookmark = null;

	// Scan through the document to find all H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Release the previous bookmark if it exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a new bookmark for this H2 tag
		$last_h2_bookmark = 'last_h2_' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
	}

	// If no H2 tags were found, return the HTML unchanged
	if ( $last_h2_bookmark === null ) {
		return $html;
	}

	// Seek back to the last H2 tag
	$processor->seek( $last_h2_bookmark );
	
	// Add the 'final-section' class to the last H2 tag
	$processor->add_class( 'final-section' );
	
	// Release the bookmark
	$processor->release_bookmark( $last_h2_bookmark );

	// Return the modified HTML
	return $processor->get_updated_html();
}
