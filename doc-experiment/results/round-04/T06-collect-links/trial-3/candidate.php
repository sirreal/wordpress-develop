<?php

/**
 * Collect all links from an HTML fragment.
 *
 * Given an HTML fragment (as found inside `<body>`), return a list describing 
 * every `A` tag that has an `href` attribute, in document order. Each entry is 
 * an associative array with 'href' and 'text' keys.
 *
 * @param string $html HTML fragment to parse
 * @return array Array of link arrays, each with 'href' and 'text' keys
 */
function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A', 'tag_closers' => 'skip' ) ) ) {
		// Check if href attribute exists
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		// Collect text content inside the A element
		$text = '';
		$a_depth = $processor->get_current_depth();

		// Use next_token to traverse inside the A element
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we've exited the A element (depth drops below opening tag depth)
			if ( $current_depth < $a_depth ) {
				break;
			}

			// Only collect text from #text nodes (not from nested tags or other token types)
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
