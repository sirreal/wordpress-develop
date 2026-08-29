<?php

function get_document_title( string $html ): ?string {
	// Use the full parser for complete HTML documents
	$processor = WP_HTML_Processor::create_full_parser( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	// Find the TITLE tag
	if ( ! $processor->next_tag( 'TITLE' ) ) {
		return null;
	}
	
	// Collect text content of the TITLE element
	$title_text = '';
	$title_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() && $processor->get_current_depth() >= $title_depth ) {
		// Check if this is the closing TITLE tag
		if ( 'TITLE' === $processor->get_token_name() && $processor->is_tag_closer() ) {
			// We've reached the closing tag, exit the loop
			break;
		}
		
		// Collect text nodes; character references are already decoded
		if ( '#text' === $processor->get_token_type() ) {
			$title_text .= $processor->get_modifiable_text();
		}
	}
	
	return $title_text;
}
