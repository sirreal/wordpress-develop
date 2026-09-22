<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in <mark>
				$output .= '<mark>';
				$output .= $processor->serialize_token();
				$output .= '</mark>';
			} else {
				$output .= $processor->serialize_token();
			}
		} else {
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
