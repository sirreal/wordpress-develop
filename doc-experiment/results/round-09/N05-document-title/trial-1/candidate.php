<?php

function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Find the TITLE tag in the document.
	if ( $processor->next_tag( 'TITLE' ) ) {
		// Check if we're on an opening tag (not a closer).
		if ( ! $processor->is_tag_closer() ) {
			// Get the text content, which is automatically decoded.
			return $processor->get_modifiable_text();
		}
	}
	
	return null;
}
