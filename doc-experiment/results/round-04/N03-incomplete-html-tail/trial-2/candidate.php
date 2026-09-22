<?php

/**
 * Determines whether the HTML document was cut off in the middle of a token.
 *
 * @param string $html The HTML string to analyze.
 * @return bool True if the input ends inside an unfinished token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Attempt to process tokens until we reach the end or find a complete token.
	while ( $processor->next_token() ) {
		// Continue scanning through tokens.
	}
	
	// If the processor paused at an incomplete token, the tail is incomplete.
	return $processor->paused_at_incomplete_token();
}
