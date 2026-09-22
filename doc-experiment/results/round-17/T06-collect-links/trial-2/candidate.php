<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
		// Skip closing tags
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		// Get href attribute
		$href = $processor->get_attribute( 'href' );

		// Only include links that have an href attribute
		if ( null === $href ) {
			continue;
		}

		// Collect text content inside the link
		$text = '';
		$link_depth = $processor->get_current_depth();

		// Move to next token and collect all text inside the A element
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we exit the A element
			if ( $current_depth < $link_depth ) {
				break;
			}

			// Collect text from #text nodes inside the link
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
