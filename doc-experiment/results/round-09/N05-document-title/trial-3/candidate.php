<?php

function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	
	if ( null === $processor ) {
		return null;
	}
	
	if ( $processor->next_tag( array( 'tag_name' => 'title' ) ) ) {
		// Get the next token which should be the text content or closing tag
		if ( $processor->next_token() ) {
			if ( '#text' === $processor->get_token_type() ) {
				return $processor->get_modifiable_text();
			}
		}
		// If there's no text token, the title is empty
		return '';
	}
	
	// No title element found
	return null;
}
