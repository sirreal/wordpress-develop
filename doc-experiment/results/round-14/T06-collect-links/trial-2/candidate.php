<?php

function collect_links( string $html ): array {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return array();
	}
	
	$links = array();
	
	// Iterate through all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Skip if there's no href attribute
		$href = $processor->get_attribute( 'href' );
		if ( $href === null ) {
			continue;
		}
		
		// Record the depth of the A tag so we can collect text only within it
		$depth_at_a_tag = $processor->get_current_depth();
		
		// Collect all text content within this A element
		$text = '';
		
		// Step into the A element and collect text nodes
		while ( $processor->next_token() && $processor->get_current_depth() > $depth_at_a_tag ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add this link to the results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
