<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_name = $processor->get_token_name();
		
		// Skip SPAN opening and closing tags
		if ( 'SPAN' === $token_name ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
