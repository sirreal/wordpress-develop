<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return empty string
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		// Skip SPAN tags (both opening and closing)
		if ( 'SPAN' === $processor->get_tag() ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
