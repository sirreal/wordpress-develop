<?php

function collect_links( string $html ): array {
	$links = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $links;
	}
	
	while ( $processor->next_tag( 'A' ) ) {
		// Only process opening A tags, not closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Check if the A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}
		
		// Record the depth of the A element
		$a_depth = $processor->get_current_depth();
		
		// Collect text content inside the A element
		$text = '';
		
		while ( $processor->next_token() && $processor->get_current_depth() >= $a_depth ) {
			// Collect text from text nodes that are within the A element
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
