<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return $html;
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a <mark> element
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
