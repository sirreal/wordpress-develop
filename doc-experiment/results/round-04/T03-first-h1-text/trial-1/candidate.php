<?php

/**
 * Extract the text content of the first H1 element.
 *
 * Given an HTML fragment, returns the text content of the first H1 element,
 * which is the concatenation of all text nodes (with character references decoded).
 * Returns null only if no H1 element exists; returns empty string if H1 contains
 * only markup with no text.
 *
 * @param string $html HTML fragment to process.
 * @return string|null Text content of first H1, or null if no H1 found.
 */
function get_first_h1_text( string $html ): ?string {
	// Create a processor for the HTML fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return null;
	}
	
	// Find the first H1 element.
	if ( ! $processor->next_tag( 'H1' ) ) {
		return null;
	}
	
	// Record the depth of the H1 to know when we've exited it.
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text content within the H1.
	$text_content = '';
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop if we've exited the H1 element.
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Only collect text tokens that are inside the H1.
		if ( '#text' === $processor->get_token_type() && $current_depth > $h1_depth ) {
			$text_content .= $processor->get_modifiable_text();
		}
	}
	
	return $text_content;
}
