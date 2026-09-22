<?php

function collect_links( string $html ): array {
	$links = array();
	
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return $links;
	}
	
	while ( $processor->next_tag( 'A' ) ) {
		$href = $processor->get_attribute( 'href' );
		
		// Skip A tags without href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Collect text content of the A tag
		$text = '';
		$depth_inside_a = $processor->get_current_depth();
		
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
			if ( '#text' === $processor->get_token_type() ) {
				$text .= $processor->get_modifiable_text();
			}
		}
		
		// Add the link to results
		$links[] = array(
			'href' => $href,
			'text' => $text,
		);
	}
	
	return $links;
}
