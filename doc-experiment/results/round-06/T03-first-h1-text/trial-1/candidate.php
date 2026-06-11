<?php

function get_first_h1_text( string $html ): ?string {
	$processor = WP_HTML_Processor::create_fragment( $html );
	
	if ( ! $processor || ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
		return null;
	}
	
	$text = '';
	$h1_depth = $processor->get_current_depth();
	
	while ( $processor->next_token() ) {
		$current_depth = $processor->get_current_depth();
		
		if ( $current_depth < $h1_depth ) {
			break;
		}
		
		if ( '#text' === $processor->get_token_type() ) {
			$text .= $processor->get_modifiable_text();
		}
	}
	
	return $text;
}
