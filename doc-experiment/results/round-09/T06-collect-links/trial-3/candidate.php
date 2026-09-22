<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$links = array();
	
	// Find all A tags
	while ( $processor->next_tag( 'A' ) ) {
		// Only include A tags with an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}
		
		// Record the depth so we know when we've left the A element
		$depth_inside_a = $processor->get_current_depth();
		
		// Collect all text content inside this A tag
		$text = '';
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add this link to our results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
