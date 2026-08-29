<?php
function mark_keyword( string $html, string $keyword ): string {
	// Create a processor for the HTML fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	// If processor creation failed, return normalized HTML without marks
	if ( null === $processor ) {
		return WP_HTML_Processor::normalize( $html ) ?? $html;
	}
	
	$output = '';
	
	// Walk through all tokens in the document
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			// Get the decoded text content
			$text = $processor->get_modifiable_text();
			
			// Check if the keyword is in the text (case-sensitive substring match)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in a mark element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Output the text token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For non-text tokens (tags, comments, etc.), serialize as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
