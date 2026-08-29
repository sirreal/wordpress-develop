<?php

/**
 * Detect whether the HTML document was cut off in the middle of an HTML token.
 *
 * Returns true when the end of the input falls inside an incomplete token
 * (e.g., an unfinished tag, an unterminated comment, or an unclosed SCRIPT
 * element whose contents run to the end). Returns false for input whose
 * tokens are all complete.
 *
 * @param string $html The HTML document to check.
 * @return bool True if the HTML ends in an incomplete token, false otherwise.
 */
function has_incomplete_html_tail( string $html ): bool {
	// Create a processor to scan the HTML document.
	$processor = new WP_HTML_Tag_Processor( $html );

	// Scan through all tokens in the document.
	// The processor will pause if it encounters an incomplete token at the end.
	while ( $processor->next_token() ) {
		// Continue scanning through all tokens.
	}

	// Check if the processor paused because of an incomplete token.
	return $processor->paused_at_incomplete_token();
}
