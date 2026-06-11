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
			// Check if decoded text contains the keyword (case-sensitive)
			if ( false !== strpos( $text, $keyword ) ) {
				// Wrap the entire text node in <mark> and serialize
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Serialize the text token normally
				$output .= $processor->serialize_token();
			}
		} else {
			// For all non-text tokens, serialize them
			$output .= $processor->serialize_token();
		}
	}

	return $output;
}
