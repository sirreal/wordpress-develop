<?php

/**
 * Detect whether the document was cut off in the middle of an HTML token.
 *
 * @param string $html The HTML document to check.
 * @return bool True if the input ends inside an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens until we reach the end.
	while ( $processor->next_token() ) {
		// Continue scanning.
	}
	
	// Check if the processor paused because it hit an incomplete token.
	return $processor->paused_at_incomplete_token();
}
