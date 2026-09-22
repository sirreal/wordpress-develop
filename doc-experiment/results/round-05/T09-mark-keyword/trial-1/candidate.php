<?php

/**
 * Highlights a keyword in HTML text by wrapping matching text nodes in <mark> tags.
 *
 * Given an HTML fragment and a keyword, returns normalized HTML where every
 * text node whose decoded text contains the keyword (case-sensitive substring
 * match) is wrapped in a <mark> element.
 *
 * @param string $html HTML fragment.
 * @param string $keyword Non-empty keyword to search for.
 * @return string Normalized HTML with <mark> tags wrapping matching text nodes.
 */
function mark_keyword( string $html, string $keyword ): string {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return '';
	}

	$output = '';
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// Check if this is a text node containing the keyword
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if decoded text contains the keyword (case-sensitive)
			if ( strpos( $text, $keyword ) !== false ) {
				// Wrap the entire text node in a <mark> tag
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Output text node as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// Output any other token as-is
			$output .= $processor->serialize_token();
		}
	}
	
	return $output;
}
