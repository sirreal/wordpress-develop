<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node and it contains the keyword
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if decoded text contains the keyword (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in <mark>
				$output .= '<mark>';
				$output .= $processor->serialize_token();
				$output .= '</mark>';
			} else {
				// No match, serialize normally
				$output .= $processor->serialize_token();
			}
		} else {
			// Not a text node, serialize normally
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
