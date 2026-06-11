<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Keep track of the last H2 tag we find
	$last_h2_bookmark = null;
	
	// Scan through all H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		// Skip tag closers - we only want opening tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set a bookmark for this H2 tag
		// We'll update it each time we find a new H2
		if ( $last_h2_bookmark ) {
			$processor->release_bookmark( $last_h2_bookmark );
		}
		$last_h2_bookmark = 'last_h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found at least one H2 tag, seek back to it and add the class
	if ( $last_h2_bookmark && $processor->seek( $last_h2_bookmark ) ) {
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( $last_h2_bookmark );
	}
	
	return $processor->get_updated_html();
}
