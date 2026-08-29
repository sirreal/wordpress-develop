<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		// Check if this A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		// Record the depth at which we found the A tag
		$depth_at_start = $processor->get_current_depth();

		// Collect text content by iterating through tokens inside the A element
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Exit when we've left the A element
			if ( $current_depth < $depth_at_start ) {
				break;
			}

			// Only collect text nodes at depth equal to or greater than the start
			if ( '#text' === $processor->get_token_type() && $current_depth >= $depth_at_start ) {
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
