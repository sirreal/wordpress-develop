<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all H2 tags, remembering the last one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$processor->set_bookmark( 'last-h2' );
	}
	
	// If we found at least one H2, seek to the last one and add the class
	if ( $processor->has_bookmark( 'last-h2' ) ) {
		$processor->seek( 'last-h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last-h2' );
	}
	
	return $processor->get_updated_html();
}
