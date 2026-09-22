<?php

function collect_links( string $html ): array {
	$links = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor ) {
		return $links;
	}
	
	while ( $processor->next_tag( 'A' ) ) {
		// Check if this A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Skip A tags without href
		if ( $href === null ) {
			continue;
		}
		
		// Record the depth inside the A tag so we can collect all text
		$depth_inside_a = $processor->get_current_depth();
		
		// Collect text content inside the A tag
		$text = '';
		while ( $processor->next_token() && $processor->get_current_depth() > $depth_inside_a ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the link to our results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
