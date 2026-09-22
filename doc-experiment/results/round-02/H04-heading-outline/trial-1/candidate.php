<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$headings = array();
	$heading_tags = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

	// Iterate through all tags in the document
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();

		// Check if this is a heading tag
		if ( in_array( $tag, $heading_tags, true ) ) {
			// Extract level from tag name (H1 -> 1, H2 -> 2, etc.)
			$level = (int) substr( $tag, 1 );

			// Capture depth to know when we exit the heading
			$depth_inside_heading = $processor->get_current_depth();
			$text = '';

			// Iterate through tokens inside the heading
			while ( $processor->next_token() && $processor->get_current_depth() > $depth_inside_heading ) {
				if ( '#text' === $processor->get_token_type() ) {
					$text .= $processor->get_modifiable_text();
				}
			}

			$headings[] = array(
				'level' => $level,
				'text'  => $text,
			);
		}
	}

	return $headings;
}
