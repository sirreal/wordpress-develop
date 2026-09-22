<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$headings = array();

	// Find all heading tags (H1 through H6)
	while ( $processor->next_tag( array( 'tag_closers' => 'skip' ) ) ) {
		$tag = $processor->get_tag();
		
		// Check if it's a heading tag
		if ( ! preg_match( '/^H([1-6])$/i', $tag, $matches ) ) {
			continue;
		}
		
		$level = (int) $matches[1];
		
		// Get the depth when we matched the heading opener
		$heading_depth = $processor->get_current_depth();
		
		// Collect text content from inside this heading
		$text = '';
		
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			$token_type = $processor->get_token_type();
			
			// If we've exited the heading, break
			if ( $current_depth < $heading_depth ) {
				break;
			}
			
			// Collect text from text nodes inside the heading
			if ( '#text' === $token_type ) {
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
