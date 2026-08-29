<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create a fragment processor to parse the HTML with full structural awareness
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	// Iterate through all tokens in the document
	while ( $processor->next_token() ) {
		// Check if this is a text node
		if ( '#text' === $processor->get_token_type() ) {
			// Get the decoded text content of this text node
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword appears in the decoded text (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// The entire text node should be wrapped in a <mark> element
				// serialize_token() will give us the properly encoded text
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Serialize the text node as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// Serialize all other tokens (tags, comments, etc.)
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
