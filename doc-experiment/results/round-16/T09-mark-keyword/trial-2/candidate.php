<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword.
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element.
				$output .= '<mark>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</mark>';
			} else {
				$output .= htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		} else {
			// For all other token types, serialize the token as-is.
			$output .= $processor->serialize_token();
		}
	}
	
	// Normalize the output by parsing and serializing it again.
	return \WP_HTML_Processor::normalize( $output ) ?? '';
}
