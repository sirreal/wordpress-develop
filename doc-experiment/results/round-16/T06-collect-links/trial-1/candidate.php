<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Get the href attribute value
		$href = $processor->get_attribute( 'href' );

		// Only process A tags with an href attribute
		if ( null === $href ) {
			continue;
		}

		// Collect text content from inside the A element
		$depth_inside_a = $processor->get_current_depth();
		$text = '';

		while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
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
