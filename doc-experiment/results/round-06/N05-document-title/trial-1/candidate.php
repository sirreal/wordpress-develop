<?php

function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	if ( null === $processor ) {
		return null;
	}

	// Find the TITLE tag
	if ( ! $processor->next_tag( 'title' ) ) {
		return null;
	}

	// Get the modifiable text from the TITLE element
	// The content inside TITLE is plaintext with character references decoded
	$title_text = '';
	
	// Move to the first token inside or after the TITLE tag
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Collect all text nodes inside the TITLE element
		if ( '#text' === $token_type ) {
			$title_text .= $processor->get_modifiable_text();
		}
		
		// Stop when we reach the closing TITLE tag
		if ( 'TITLE' === $processor->get_tag() && $processor->is_tag_closer() ) {
			break;
		}
	}
	
	return $title_text;
}
