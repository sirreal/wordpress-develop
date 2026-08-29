<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( 'h1' ) ) {
		return null;
	}
	
	// Record the depth of the H1 element.
	$h1_depth = $processor->get_current_depth();
	
	$text = '';
	
	// Walk through all tokens inside the H1 element.
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		// Stop when we exit the H1 element.
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		// Collect text from #text nodes inside the H1.
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
