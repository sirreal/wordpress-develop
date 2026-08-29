<?php

/**
 * Detect whether the document was cut off in the middle of an HTML token.
 *
 * @param string $html The HTML string to check.
 * @return bool True if the HTML ends with an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Drain all tokens to scan through the entire document
	while ( $processor->next_token() ) {
		continue;
	}
	
	// Check if the processor paused at an incomplete token
	return $processor->paused_at_incomplete_token();
}
