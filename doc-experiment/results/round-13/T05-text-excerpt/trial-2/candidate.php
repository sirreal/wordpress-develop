<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
	// If max_codepoints is zero or negative, return empty string
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create a fragment processor for the HTML
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Iterate through all tokens
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Only collect from text nodes
		if ( '#text' === $token_type ) {
			$node_text = $processor->get_modifiable_text();
			
			// Count codepoints in the node text
			$node_codepoints = mb_strlen( $node_text, 'UTF-8' );
			$remaining = $max_codepoints - $codepoint_count;

			if ( $node_codepoints <= $remaining ) {
				// Entire node fits
				$text .= $node_text;
				$codepoint_count += $node_codepoints;
			} else {
				// Need to truncate this node
				$text .= mb_substr( $node_text, 0, $remaining, 'UTF-8' );
				$codepoint_count = $max_codepoints;
				break;
			}

			// Stop if we've reached the limit
			if ( $codepoint_count >= $max_codepoints ) {
				break;
			}
		}
	}

	return $text;
}
