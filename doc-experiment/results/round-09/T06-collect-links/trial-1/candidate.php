<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();

	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		$href = $processor->get_attribute( 'href' );

		// Skip A tags without href attribute
		if ( $href === null ) {
			continue;
		}

		// Collect text content from inside the A tag
		$text = '';
		$depth_inside_a = $processor->get_current_depth();

		// Walk through tokens while inside the A element
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
