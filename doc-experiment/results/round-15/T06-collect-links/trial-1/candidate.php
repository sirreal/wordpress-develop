<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		$href = $processor->get_attribute( 'href' );

		// Skip A tags without href attribute
		if ( null === $href ) {
			continue;
		}

		// Record the depth of the A tag opening
		$a_tag_depth = $processor->get_current_depth();

		// Collect text content inside the A tag
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Stop when we exit the A tag
			if ( $current_depth < $a_tag_depth ) {
				break;
			}

			// Collect text nodes that are direct or indirect children
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
