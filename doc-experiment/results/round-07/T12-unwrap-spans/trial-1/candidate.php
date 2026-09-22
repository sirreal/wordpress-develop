<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	$span_depth = 0;
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		$token_type = $processor->get_token_type();
		
		// Check if this is a SPAN tag
		if ( '#tag' === $token_type && 'SPAN' === $tag ) {
			if ( $processor->is_tag_closer() ) {
				// This is a closing </span> tag
				$span_depth--;
			} else {
				// This is an opening <span> tag
				$span_depth++;
			}
			// Skip serializing SPAN tags
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	// Normalize the output by re-parsing and serializing
	$normalized = WP_HTML_Processor::normalize( $output );
	
	return $normalized ?? '';
}
