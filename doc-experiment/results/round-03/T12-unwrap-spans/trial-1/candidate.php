<?php

function unwrap_spans( string $html ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag = $processor->get_tag();
		
		// Skip opening SPAN tags
		if ( 'SPAN' === $tag && ! $processor->is_tag_closer() ) {
			continue;
		}
		
		// Skip closing SPAN tags
		if ( 'SPAN' === $tag && $processor->is_tag_closer() ) {
			continue;
		}
		
		// Serialize all other tokens
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
