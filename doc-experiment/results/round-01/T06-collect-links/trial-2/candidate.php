<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		// Skip closing tags — next_tag() by default only visits openers, but be safe.
		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$href = $processor->get_attribute( 'href' );

		// Exclude A tags without an href attribute.
		if ( null === $href ) {
			continue;
		}

		// Record depth at the A opener to know when we've exited.
		$depth_inside_a = $processor->get_current_depth();

		// Collect all text nodes inside this A element.
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
