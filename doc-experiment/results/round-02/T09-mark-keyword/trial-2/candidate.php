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
			
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element
				// We need to properly escape the text for HTML
				$output .= '<mark>' . $text . '</mark>';
			} else {
				$output .= $text;
			}
		} else {
			// For all other token types (tags, comments, etc.), serialize them
			$output .= $processor->serialize_token();
		}
	}
	
	// Now normalize the reconstructed HTML
	$normalized = WP_HTML_Processor::normalize( $output );
	return $normalized !== null ? $normalized : '';
}
