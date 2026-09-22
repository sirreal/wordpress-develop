<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( $processor === null ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword (case-sensitive)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in <mark>
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other tokens (tags, comments, etc.), serialize as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
