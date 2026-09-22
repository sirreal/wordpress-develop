<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive max_codepoints
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Create HTML processor from the fragment
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';
	$codepoint_count = 0;

	// Walk through all tokens in the HTML document
	while ( $processor->next_token() ) {
		// Check if this is a text node
		if ( '#text' === $processor->get_token_type() ) {
			$node_text = $processor->get_modifiable_text();
			
			// Calculate how many codepoints we can still add
			$remaining = $max_codepoints - $codepoint_count;
			
			if ( $remaining <= 0 ) {
				break;
			}

			// Count codepoints in this text node
			$node_codepoints = mb_strlen( $node_text, 'UTF-8' );

			if ( $node_codepoints <= $remaining ) {
				// We can add the entire text node
				$text .= $node_text;
				$codepoint_count += $node_codepoints;
			} else {
				// We need to truncate this text node
				$text .= mb_substr( $node_text, 0, $remaining, 'UTF-8' );
				$codepoint_count += $remaining;
				break;
			}
		}
	}

	return $text;
}
