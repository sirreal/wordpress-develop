<?php

/**
 * Extract the text content of the first H1 element.
 *
 * @param string $html HTML fragment content.
 * @return string|null Text content of the first H1 element, or null if no H1 found.
 */
function get_first_h1_text( string $html ): ?string {
	// Create a fragment processor for the HTML.
	$processor = WP_HTML_Processor::create_fragment( $html );

	// If processor creation failed, return null.
	if ( null === $processor ) {
		return null;
	}

	// Find the first H1 tag.
	if ( ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
		return null;
	}

	// Record the depth at the H1 opening tag.
	$h1_depth = $processor->get_current_depth();

	// Accumulate text content.
	$text = '';

	// Walk through all tokens inside the H1 element.
	while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
		// Only accumulate text from #text tokens.
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}

	// Return the accumulated text, or empty string if no text found.
	return $text;
}
