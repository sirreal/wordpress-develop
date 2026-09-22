<?php

function has_incomplete_html_tail( string $html ): bool {
	// Create a Tag Processor to scan through the HTML
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens in the document
	while ( $processor->next_token() ) {
		// Continue scanning until we reach the end
		continue;
	}
	
	// Return whether the processor paused at an incomplete token
	return $processor->paused_at_incomplete_token();
}
