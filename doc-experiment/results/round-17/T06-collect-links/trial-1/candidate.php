<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$links = array();
	
	while ( $processor->next_tag( 'a' ) ) {
		$href = $processor->get_attribute( 'href' );
		
		// Only include links that have an href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Collect text content inside the A element
		$depth_inside_link = $processor->get_current_depth();
		$text = '';
		
		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_link ) {
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
