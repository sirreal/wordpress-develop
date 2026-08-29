<?php

function mark_keyword( string $html, string $keyword ): string {
	// Create an HTML processor from the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$output = '';

	// Walk through all tokens and rewrite, adding <mark> wrappers where needed
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Handle text nodes
		if ( '#text' === $token_type ) {
			$text = $processor->get_modifiable_text();
			
			// Check if the decoded text contains the keyword
			if ( false !== strpos( $text, $keyword ) ) {
				// Emit opening mark tag, then the text token, then closing mark tag
				$output .= '<mark>' . $processor->serialize_token() . '</mark>';
			} else {
				// Text doesn't contain keyword, keep as-is
				$output .= $processor->serialize_token();
			}
		} else {
			// For all other token types (tags, comments, etc.), serialize as-is
			$output .= $processor->serialize_token();
		}
	}

	return $output;
}
