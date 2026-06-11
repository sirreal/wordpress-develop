<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node containing the keyword
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
				continue;
			}
		}
		
		// For all other tokens, serialize normally
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
