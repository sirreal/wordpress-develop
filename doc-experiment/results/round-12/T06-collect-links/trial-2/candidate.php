<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$links = array();
	
	// Find each A tag in the document
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Get the href attribute value
		$href = $processor->get_attribute( 'href' );
		
		// Only include A tags that have an href attribute
		if ( null !== $href ) {
			// Record the depth so we can collect text only within this A element
			$depth_inside_a = $processor->get_current_depth();
			
			// Collect text content from within the A element
			$text = '';
			while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
				// Only collect text nodes, not tag openers/closers
				if ( '#text' === $processor->get_token_type() ) {
					$text .= $processor->get_modifiable_text();
				}
			}
			
			$links[] = array(
				'href' => $href,
				'text' => $text,
			);
		}
	}
	
	return $links;
}
