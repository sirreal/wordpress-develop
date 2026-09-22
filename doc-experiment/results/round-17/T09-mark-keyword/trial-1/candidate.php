<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		// If processor creation fails, return normalized HTML without mark wrapping
		return WP_HTML_Processor::normalize( $html ) ?? '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword (case-sensitive substring match)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in a mark element
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
				continue;
			}
		}
		
		// For all other tokens, serialize normally
		$output .= $processor->serialize_token();
	}
	
	return $output;
}
