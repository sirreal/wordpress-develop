<?php
/**
 * Build a heading outline from HTML fragment.
 *
 * @param string $html HTML fragment to process.
 * @return array Array of heading entries with 'level' and 'text' keys.
 */
function heading_outline( string $html ): array {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$outline = array();

	// Find each heading tag (H1-H6) by iterating through all tags
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		// Check if this is a heading tag and not a closer
		if ( null === $tag || $processor->is_tag_closer() ) {
			continue;
		}

		// Only process heading tags
		if ( ! preg_match( '/^H[1-6]$/', $tag ) ) {
			continue;
		}

		// Extract level from tag name (H1 -> 1, H2 -> 2, etc.)
		$level = (int) substr( $tag, 1 );

		// Collect text content of this heading
		$text = '';
		$heading_depth = $processor->get_current_depth();

		// Step through tokens inside the heading until we exit it
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Exit when we've left the heading element
			if ( $current_depth < $heading_depth ) {
				break;
			}

			// Collect text from #text tokens
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$outline[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $outline;
}
