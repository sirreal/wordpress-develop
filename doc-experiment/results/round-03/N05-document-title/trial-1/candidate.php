<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the TITLE tag
	if ( ! $processor->next_tag( array( 'tag_name' => 'TITLE' ) ) ) {
		return null;
	}
	
	// Move to the next token to get the content inside TITLE
	if ( ! $processor->next_token() ) {
		// Empty title tag
		return '';
	}
	
	// Check if this is a text token
	if ( '#text' === $processor->get_token_type() ) {
		// Get the modifiable text (character references are already decoded)
		return $processor->get_modifiable_text();
	}
	
	// If no text content, return empty string
	return '';
}
