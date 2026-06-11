<?php

function has_incomplete_html_tail( string $html ): bool {
	// Create a Tag Processor instance to scan through the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Keep scanning through tokens until we reach the end
	while ( $processor->next_token() ) {
		// Continue scanning
	}
	
	// If the processor paused at an incomplete token, return true
	if ( $processor->paused_at_incomplete_token() ) {
		return true;
	}
	
	return false;
}
