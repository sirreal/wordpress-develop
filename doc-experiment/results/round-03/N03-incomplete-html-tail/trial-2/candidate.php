<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Try to scan through all tokens in the HTML document
	while ( $processor->next_token() ) {
		// Continue scanning
	}
	
	// If the processor paused because it reached an incomplete token,
	// return true; otherwise return false
	return $processor->paused_at_incomplete_token();
}
