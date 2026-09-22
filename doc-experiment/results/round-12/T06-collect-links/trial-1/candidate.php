<?php

/**
 * Collect all links from HTML fragment.
 *
 * Given an HTML fragment, returns a list of associative arrays describing
 * every A tag with an href attribute, in document order.
 *
 * @param string $html HTML fragment (as found inside <body>).
 * @return array Array of links, each with 'href' and 'text' keys.
 */
function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$links = array();
	
	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Skip closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}
		
		// Check if href attribute exists
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}
		
		// Record the depth of the A tag so we know when to stop collecting text
		$link_depth = $processor->get_current_depth();
		
		// Collect text content of the link
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();
			
			// Stop when we exit the A element
			if ( $current_depth < $link_depth ) {
				break;
			}
			
			// Collect text nodes that are direct children or nested
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
