<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	// Find each A tag
	while ( $processor->next_tag( 'A' ) ) {
		// Check if the A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		// Record the depth of the A tag so we know when we exit it
		$a_depth = $processor->get_current_depth();

		// Collect all text nodes inside the A tag
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// If we've exited the A tag, stop collecting
			if ( $current_depth < $a_depth ) {
				break;
			}

			// Collect text nodes that are direct or nested children
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
