<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Iterate through all tokens in the document
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$tag_name   = $processor->get_tag();
		
		// Skip SPAN opening and closing tags, but keep their content
		if ( '#tag' === $token_type && 'SPAN' === $tag_name ) {
			continue;
		}
		
		// Serialize all other tokens
		$token_output = $processor->serialize_token();
		if ( ! empty( $token_output ) ) {
			$output .= $token_output;
		}
	}
	
	// Normalize the accumulated output to ensure proper formatting
	$normalized = WP_HTML_Processor::normalize( $output );
	return $normalized ?? '';
}
