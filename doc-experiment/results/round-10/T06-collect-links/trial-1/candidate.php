<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		// Skip A tags without href attribute
		$href = $processor->get_attribute( 'href' );
		if ( null === $href ) {
			continue;
		}

		// Record the depth of this A element
		$link_depth = $processor->get_current_depth();

		// Collect text content inside this A element
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// Exit when we've closed the A element
			if ( $current_depth < $link_depth ) {
				break;
			}

			// Collect text nodes that are direct children or deeper
			if ( '#text' === $processor->get_token_type() && $current_depth > $link_depth ) {
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
