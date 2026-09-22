<?php

/**
 * Extract the document title from a complete HTML document.
 *
 * Given a complete HTML document, returns the text content of the <title> element
 * with character references decoded, or null if no <title> element exists.
 *
 * @param string $html The complete HTML document.
 * @return string|null The title text with character references decoded, or null if no title element.
 */
function get_document_title( string $html ): ?string {
	// Create a processor for the full HTML document
	$processor = WP_HTML_Tag_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Find the TITLE tag
	if ( ! $processor->next_tag( 'TITLE' ) ) {
		return null;
	}
	
	// Get the modifiable text from the TITLE element
	// Character references are automatically decoded by the API
	$title = $processor->get_modifiable_text();
	
	// Return the title text (empty string if title is empty, not null)
	return $title;
}
