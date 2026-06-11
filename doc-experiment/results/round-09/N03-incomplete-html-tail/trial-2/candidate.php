<?php

/**
 * Detect if an HTML document ends with an incomplete HTML token.
 *
 * @param string $html The HTML document to check.
 * @return bool True if the document ends mid-token (incomplete tag, unterminated comment, etc.),
 *              false if all tokens are complete.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to the end of the document
	while ( $processor->next_token() ) {
		continue;
	}
	
	// Check if the processor paused due to an incomplete token
	return $processor->paused_at_incomplete_token();
}
