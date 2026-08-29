<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( null === $processor ) {
		return array();
	}

	$headings        = array();
	$current_heading = null; // null or [ 'level' => int, 'text' => string ]

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();

		if ( '#tag' === $token_type ) {
			$tag_name = $processor->get_tag();

			// Check if this is a heading tag (H1–H6).
			if ( in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
				if ( $processor->is_tag_closer() ) {
					// End of a heading — save it.
					if ( null !== $current_heading ) {
						$headings[]      = $current_heading;
						$current_heading = null;
					}
				} else {
					// Opening a heading — start collecting text.
					$level           = (int) $tag_name[1];
					$current_heading = array(
						'level' => $level,
						'text'  => '',
					);
				}
			}
		} elseif ( '#text' === $token_type ) {
			// Accumulate text inside heading.
			if ( null !== $current_heading ) {
				$current_heading['text'] .= $processor->get_modifiable_text();
			}
		}
	}

	return $headings;
}
