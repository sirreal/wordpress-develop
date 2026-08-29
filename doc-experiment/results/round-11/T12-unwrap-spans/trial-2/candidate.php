<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return the input as-is or empty
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip SPAN opening and closing tags, but keep everything else
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
