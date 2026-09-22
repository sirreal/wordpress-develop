<?php
/**
 * Extract plain-text content from HTML with a codepoint limit.
 *
 * Given an HTML fragment, returns the concatenation of all text nodes in
 * document order, with character references decoded, truncated to a maximum
 * number of Unicode codepoints.
 *
 * @param string $html          HTML fragment to extract text from.
 * @param int    $max_codepoints Maximum number of Unicode codepoints to include.
 *
 * @return string Plain-text excerpt, truncated as needed.
 */
function html_text_excerpt( string $html, int $max_codepoints ): string {
	// Handle non-positive codepoint limits.
	if ( $max_codepoints <= 0 ) {
		return '';
	}

	// Parse the HTML fragment.
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return '';
	}

	$text = '';

	// Walk through all tokens and collect text nodes.
	while ( $processor->next_token() ) {
		// Only process text nodes.
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		// Get the decoded text from the text node.
		$node_text = $processor->get_modifiable_text();

		// Calculate remaining codepoints available.
		$current_length = mb_strlen( $text, 'UTF-8' );
		$remaining      = $max_codepoints - $current_length;

		if ( $remaining <= 0 ) {
			// Already at the limit, stop processing.
			break;
		}

		$node_length = mb_strlen( $node_text, 'UTF-8' );

		if ( $node_length <= $remaining ) {
			// Entire node fits within the limit.
			$text .= $node_text;
		} else {
			// Node exceeds the limit, truncate it.
			$text .= mb_substr( $node_text, 0, $remaining, 'UTF-8' );
			break;
		}
	}

	return $text;
}