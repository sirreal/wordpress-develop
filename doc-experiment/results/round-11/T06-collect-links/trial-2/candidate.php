<?php

function collect_links( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$links = array();

	// Find all A tags with href attribute
	while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
		$href = $processor->get_attribute( 'href' );
		
		// Skip A tags without href attribute
		if ( null === $href ) {
			continue;
		}

		// Record the depth inside the A element
		$a_depth = $processor->get_current_depth();
		
		// Collect text content inside the A element
		$text = '';
		while ( $processor->next_token() && $processor->get_current_depth() > $a_depth ) {
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

	return $links;
}
