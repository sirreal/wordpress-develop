<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		// Skip closing tags.
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );

		// Skip A tags without an href attribute.
		if ( null === $href ) {
			continue;
		}

		$a_depth = $processor->get_current_depth();
		$text    = '';

		// Walk tokens inside the A element, accumulating text nodes.
		while ( $processor->next_token() && $processor->get_current_depth() >= $a_depth ) {
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
