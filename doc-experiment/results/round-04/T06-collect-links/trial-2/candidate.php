<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	// Find each A tag
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Only process opening tags, not closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Check if the A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		// Record the depth so we know when we exit this A element
		$link_depth = $processor->get_current_depth();

		// Collect all text content inside this A element
		$text = '';
		while ( $processor->next_token() && $processor->get_current_depth() >= $link_depth ) {
			// Collect text from #text tokens
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
