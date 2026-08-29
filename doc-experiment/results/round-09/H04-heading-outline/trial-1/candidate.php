<?php
/**
 * Build a heading outline from HTML fragment.
 *
 * @param string $html HTML fragment to parse.
 * @return array List of headings with level and text content.
 */
function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$outline = array();
	$heading_tags = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

	while ( $processor->next_tag( array( 'tag_name' => null ) ) ) {
		$tag = $processor->get_tag();

		if ( null === $tag || ! in_array( $tag, $heading_tags, true ) ) {
			continue;
		}

		// Extract the level from the tag name (H1 -> 1, H2 -> 2, etc.)
		$level = (int) substr( $tag, 1 );

		// Record the opening tag and its depth to know when we exit this element.
		$depth_inside_heading = $processor->get_current_depth();
		$text = '';

		// Walk through all tokens inside this heading until we exit it.
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_heading ) {
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
