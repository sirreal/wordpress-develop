<?php

function collect_links( string $html ): array {
	$processor = \WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_token() ) {
		// Look for opening A tags with href attribute
		if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
			$href = $processor->get_attribute( 'href' );
			
			// Skip A tags without href attribute
			if ( null === $href ) {
				continue;
			}

			// Start collecting text content inside this A tag
			$text = '';
			$a_depth = $processor->get_current_depth();

			// Iterate through tokens inside the A tag
			while ( $processor->next_token() ) {
				$current_depth = $processor->get_current_depth();

				// Stop when we reach the closing tag of the A element
				if ( $current_depth < $a_depth ) {
					break;
				}

				// Collect text from text nodes at the same depth or deeper
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
	}

	return $links;
}
