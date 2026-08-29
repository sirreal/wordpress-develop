<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		$href = $processor->get_attribute( 'href' );

		// Skip A tags without an href attribute
		if ( null === $href ) {
			continue;
		}

		// Collect text content inside the A element
		$text = '';
		$link_depth = $processor->get_current_depth();

		while ( $processor->next_token() && $processor->get_current_depth() >= $link_depth ) {
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
