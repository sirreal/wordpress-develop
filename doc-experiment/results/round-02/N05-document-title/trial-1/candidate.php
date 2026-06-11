<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the TITLE tag
	if ( ! $processor->next_tag( 'TITLE' ) ) {
		return null;
	}
	
	// Move to the next token to get the text content inside TITLE
	if ( ! $processor->next_token() ) {
		// TITLE tag exists but has no content (empty or self-closing)
		return '';
	}
	
	// Get the modifiable text. For TITLE elements, character references are decoded.
	$title_text = $processor->get_modifiable_text();
	
	// Return the title text (may be empty string for empty title)
	return $title_text;
}
