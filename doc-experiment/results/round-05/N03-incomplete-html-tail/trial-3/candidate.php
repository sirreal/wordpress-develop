<?php

/**
 * Detect whether the document was cut off in the middle of an HTML token.
 *
 * @param string $html The HTML document to check.
 * @return bool True if the document ends in the middle of an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to the end of the document.
	while ( $processor->next_token() ) {
		// Continue scanning through all tokens.
	}
	
	// Check if the processor paused at an incomplete token.
	return $processor->paused_at_incomplete_token();
}
