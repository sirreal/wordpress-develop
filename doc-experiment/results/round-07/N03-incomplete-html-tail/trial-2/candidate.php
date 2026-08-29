<?php

/**
 * Determines whether the HTML was cut off in the middle of an incomplete token.
 *
 * Returns true when the end of the input falls inside an incomplete syntax element
 * (e.g., unfinished tag, unterminated comment, unclosed SCRIPT element), and false
 * when all tokens are lexically complete.
 *
 * @param string $html The HTML content to check.
 * @return bool True if the HTML ends inside an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to reach the end of the document.
	while ( $processor->next_token() ) {
		// Continue scanning until we've processed all tokens.
		continue;
	}
	
	// Check if the processor paused because it reached an incomplete token.
	return $processor->paused_at_incomplete_token();
}
