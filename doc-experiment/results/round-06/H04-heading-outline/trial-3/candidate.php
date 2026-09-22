<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$headings = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		// Check if this is a tag token
		if ( '#tag' !== $token_type ) {
			continue;
		}

		$tag = $processor->get_tag();
		if ( ! $tag ) {
			continue;
		}

		// Skip closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Check if it's a heading tag (H1-H6)
		if ( ! preg_match( '/^H[1-6]$/i', $tag ) ) {
			continue;
		}

		// Extract heading level from tag name
		$level = (int) substr( $tag, 1 );

		// Collect all text content inside the heading
		$text = '';
		$depth_inside_heading = $processor->get_current_depth();

		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_heading ) {
			$current_token_type = $processor->get_token_type();

			// Text nodes have already-decoded content
			if ( '#text' === $current_token_type ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		// Add the heading to the outline
		$headings[] = array(
			'level' => $level,
			'text'  => $text,
		);
	}

	return $headings;
}
