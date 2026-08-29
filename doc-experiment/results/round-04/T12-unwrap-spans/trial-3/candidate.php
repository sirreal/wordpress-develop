<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// Return the original HTML if processor creation fails
		return $html;
	}
	
	$output = '';
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip opening and closing SPAN tags
		if ( 'SPAN' === $tag ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
