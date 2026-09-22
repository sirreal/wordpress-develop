<?php

/**
 * Determine whether the document was cut off in the middle of an HTML token.
 *
 * @param string $html The HTML document to check.
 * @return bool True if the input ends inside an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Drain all tokens in the document to reach the end.
	while ( $processor->next_token() ) {
		continue;
	}
	
	// Check if the processor paused because the input ended mid-token.
	return $processor->paused_at_incomplete_token();
}
