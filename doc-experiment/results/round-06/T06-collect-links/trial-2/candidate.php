<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		$href = $processor->get_attribute( 'href' );

		// Skip A tags without href attribute
		if ( null === $href ) {
			continue;
		}

		// Record the depth of the A element to know when we've exited it
		$a_depth = $processor->get_current_depth();

		// Collect all text content inside the A element
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we've exited the A element
			if ( $current_depth < $a_depth ) {
				break;
			}

			// Only collect text tokens that are at the same depth as we entered,
			// or deeper (inside nested elements)
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
