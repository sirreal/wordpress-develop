<?php

function heading_outline( string $html ): array {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( null === $processor ) {
		return array();
	}
	
	$headings = array();
	
	while ( $processor->next_token() ) {
		$token_name = $processor->get_token_name();
		
		// Check if this is a heading opener
		if ( null !== $token_name && preg_match( '/^H[1-6]$/i', $token_name ) && ! $processor->is_tag_closer() ) {
			$level = (int) substr( strtoupper( $token_name ), 1 );
			$depth = $processor->get_current_depth();
			$text = '';
			
			// Collect all text inside this heading
			while ( $processor->next_token() ) {
				$current_depth = $processor->get_current_depth();
				
				// Stop when we exit the heading element
				if ( $current_depth < $depth ) {
					break;
				}
				
				$token_type = $processor->get_token_type();
				
				// Accumulate text from #text nodes
				if ( '#text' === $token_type ) {
					$text .= $processor->get_modifiable_text();
				}
			}
			
			$headings[] = array(
				'level' => $level,
				'text'  => $text,
			);
		}
	}
	
	return $headings;
}
