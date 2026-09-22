<?php

/**
 * Extract the first heading's text.
 *
 * Given an HTML fragment, return the text content of the first H1 element:
 * the concatenation of all text nodes inside it, including text inside nested
 * elements, with character references decoded. Markup contributes nothing — an
 * H1 containing only an image has text content "" (empty string, not null).
 *
 * Returns null only when the document contains no H1 element.
 *
 * @param string $html HTML fragment to process.
 * @return string|null Text content of the first H1 element, or null if no H1 exists.
 */
function get_first_h1_text( string $html ): ?string {
	// Create an HTML processor to parse the fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If creation failed, there's no valid H1.
	if ( null === $processor ) {
		return null;
	}
	
	// Find the first H1 tag.
	if ( ! $processor->next_tag( array( 'tag_name' => 'h1' ) ) ) {
		return null;
	}
	
	// Record the depth of the H1 so we can collect text only inside it.
	$h1_depth = $processor->get_current_depth();
	
	// Collect all text nodes inside the H1.
	$text = '';
	
	// Walk through all tokens at the H1's depth and below.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop once we've exited the H1 (depth is lower than H1's depth).
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text tokens that are inside the H1.
		if ( '#text' === $processor->get_token_type() && $current_depth >= $h1_depth ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	// Return the collected text (may be empty string if H1 has no text).
	return $text;
}
