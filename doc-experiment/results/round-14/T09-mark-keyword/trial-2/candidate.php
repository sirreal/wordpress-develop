<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node containing the keyword
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node with <mark> tags
				$output .= '<mark>' . $text . '</mark>';
			} else {
				$output .= $text;
			}
		} else {
			// For all other tokens, serialize them as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
