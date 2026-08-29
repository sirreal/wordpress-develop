<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$links = array();
	
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Get the href attribute
		$href = $processor->get_attribute( 'href' );
		
		// Skip if there's no href attribute
		if ( null === $href ) {
			continue;
		}
		
		// Record the depth inside the link element
		$link_depth = $processor->get_current_depth();
		
		// Collect all text content inside the link
		$text = '';
		while ( $processor->next_token() && $processor->get_current_depth() >= $link_depth ) {
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
