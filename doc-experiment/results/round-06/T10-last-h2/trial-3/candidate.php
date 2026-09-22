<?php
function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track if we've found any H2 tags
	$last_h2_bookmark = null;
	
	// Scan through all tags looking for H2 tags
	while ( $processor->next_tag( array( 'tag_name' => 'H2' ) ) ) {
		// Only process opening tags, not closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Set or update the bookmark to this H2 position
		$last_h2_bookmark = 'last_h2';
		$processor->set_bookmark( $last_h2_bookmark );
	}
	
	// If we found an H2 tag, seek to the last one and add the class
	if ( $last_h2_bookmark && $processor->has_bookmark( $last_h2_bookmark ) ) {
		$processor->seek( $last_h2_bookmark );
		$processor->add_class( 'final-section' );
	}
	
	return $processor->get_updated_html();
}
