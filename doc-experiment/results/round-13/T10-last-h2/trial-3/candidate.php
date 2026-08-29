<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag by setting a bookmark on each match
	$last_h2_bookmark = null;
	$found_any_h2 = false;
	
	while ( $processor->next_tag( 'h2' ) ) {
		// Release previous bookmark if it exists
		if ( $last_h2_bookmark !== null ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		
		// Set a bookmark for this H2
		$last_h2_bookmark = 'last_h2_' . uniqid();
		$processor->set_bookmark( $last_h2_bookmark );
		$found_any_h2 = true;
	}
	
	// If we found at least one H2, seek to the last one and add the class
	if ( $found_any_h2 && $last_h2_bookmark !== null && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
