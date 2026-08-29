<?php

function mark_last_h2( string $html ): string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Track the last H2 tag by continuously resetting a bookmark as we find each one
	while ( $processor->next_tag( array( 'tag_name' => 'h2' ) ) ) {
		$processor->set_bookmark( 'last_h2' );
	}
	
	// If we found an H2 tag, seek back to it and add the class
	if ( $processor->has_bookmark( 'last_h2' ) ) {
		$processor->seek( 'last_h2' );
		$processor->add_class( 'final-section' );
		$processor->release_bookmark( 'last_h2' );
	}
	
	return $processor->get_updated_html();
}
