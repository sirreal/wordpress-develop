<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();

	// Find all A tags
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Only process A tags that have an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( $href === null ) {
			continue;
		}

		// Record the starting depth to know when we've left the A element
		$depth_inside_a = $processor->get_current_depth();
		$text = '';

		// Collect all text content inside the A element
		while ( $processor->next_token() && $processor->get_current_depth() > $depth_inside_a ) {
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
