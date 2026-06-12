<?php

function unwrap_spans( string $html ): string {
	// Create an HTML processor for the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Walk through all tokens in the document
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();
		
		// Skip SPAN opening and closing tags
		if ( 'SPAN' === $tag_name ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
