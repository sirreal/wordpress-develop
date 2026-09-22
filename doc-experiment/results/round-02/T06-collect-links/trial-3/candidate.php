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
		if ( $href === null ) {
			continue;
		}

		// Record the depth of the A tag so we can detect when we exit it
		$a_depth = $processor->get_current_depth();

		// Collect text inside the A tag
		$text = '';
		while ( $processor->next_token() ) {
			$current_depth = $processor->get_current_depth();

			// If we've exited the A tag, stop collecting text
			if ( $current_depth < $a_depth ) {
				break;
			}

			// Only collect text that's directly or indirectly inside the A tag
			if ( $current_depth >= $a_depth && '#text' === $processor->get_token_type() ) {
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
