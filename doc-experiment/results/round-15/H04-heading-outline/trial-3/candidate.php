<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	$current_heading = null;
	
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$token_name = $processor->get_token_name();
		
		// Check if this is a heading tag
		if ( in_array( $token_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
			// If we're closing a heading tag, save it
			if ( $processor->is_tag_closer() && null !== $current_heading ) {
				$headings[] = $current_heading;
				$current_heading = null;
			}
			// If we're opening a heading tag, start collecting text
			elseif ( ! $processor->is_tag_closer() ) {
				$level = (int) substr( $token_name, 1 );
				$current_heading = array(
					'level' => $level,
					'text'  => '',
				);
			}
		}
		// Collect text nodes when inside a heading
		elseif ( null !== $current_heading && '#text' === $token_type ) {
			$current_heading['text'] .= $processor->get_modifiable_text();
		}
	}
	
	return $headings;
}
