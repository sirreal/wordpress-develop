<?php
/**
 * Detect truncated HTML
 * 
 * Determines whether the document was cut off in the middle of an HTML token.
 * 
 * @param string $html The HTML string to check.
 * @return bool True if the input ends inside an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to the end of the document
	while ( $processor->next_token() ) {
		// Continue scanning
	}
	
	// After scanning to the end, check if we paused at an incomplete token
	return $processor->paused_at_incomplete_token();
}
