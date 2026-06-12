<?php

/**
 * Remove span wrappers
 *
 * Given an HTML fragment (as found inside `<body>`), remove every `SPAN`
 * element while keeping its contents in place, and return a **normalized**
 * serialization of the result. Spans nested inside other spans are also
 * removed (their contents remain). All attributes on removed spans are
 * discarded with them.
 *
 * The output is normalized HTML: optional tags are closed, attribute values
 * double-quoted, text re-encoded canonically. Apart from the removed spans it
 * is exactly the normalized form of the input.
 *
 * @param string $html HTML fragment to process.
 * @return string Normalized HTML with SPAN elements removed.
 */
function unwrap_spans( string $html ): string {
	// Create an HTML processor in fragment mode (within BODY context).
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, return empty string.
	if ( $processor === null ) {
		return '';
	}
	
	// Build the output by iterating through all tokens.
	$output = '';
	
	while ( $processor->next_token() ) {
		$tag_name = $processor->get_tag();
		
		// Skip both opening and closing SPAN tags.
		if ( 'SPAN' === $tag_name ) {
			continue;
		}
		
		// Serialize all other tokens (tags, text, comments, etc.).
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
