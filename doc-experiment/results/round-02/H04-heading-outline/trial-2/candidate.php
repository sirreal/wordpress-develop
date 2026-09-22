<?php
function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$headings = array();

	// Find all H1-H6 tags
	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag = $processor->get_tag();

		// Check if it's a heading tag
		if ( ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			continue;
		}

		// Extract level from tag name (H1 -> 1, H2 -> 2, etc.)
		$level = (int) substr( $tag, 1 );

		// Skip if it's a closing tag
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Collect text content inside the heading
		$text = '';
		$heading_depth = $processor->get_current_depth();

		// Read all tokens inside this heading
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we exit the heading
			if ( $current_depth < $heading_depth ) {
				break;
			}

			// Only collect text nodes at the immediate nesting level or deeper
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$headings[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $headings;
}
