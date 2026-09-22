<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword as a case-sensitive substring
			if ( str_contains( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Emit the token as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// Emit non-text tokens as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
