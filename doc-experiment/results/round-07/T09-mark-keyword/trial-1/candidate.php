<?php

function mark_keyword( string $html, string $keyword ): string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}
	
	$output = '';
	
	while ( $processor->next_token() ) {
		if ( '#text' === $processor->get_token_type() ) {
			$text = $processor->get_modifiable_text();
			
			// Check if decoded text contains keyword (case-sensitive substring match)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in <mark> tags
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				$output .= $processor->serialize_token();
			}
		} else {
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
