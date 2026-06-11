<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( $processor === null ) {
		return array();
	}

	$links = array();

	while ( $processor->next_tag( 'A' ) ) {
		// Check if this A tag has an href attribute
		$href = $processor->get_attribute( 'href' );
		if ( $href === null ) {
			continue;
		}

		// Collect text content of this A element
		$text = '';
		$depth_inside_a = $processor->get_current_depth();

		// Iterate through tokens inside the A element
		while ( $processor->next_token() && $processor->get_current_depth() > $depth_inside_a ) {
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
