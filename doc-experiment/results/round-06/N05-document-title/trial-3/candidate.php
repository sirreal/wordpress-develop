<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Find the title tag
	if ( ! $processor->next_tag( 'title' ) ) {
		return null;
	}
	
	// Look for the text content within the title element
	$title_text = '';
	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		
		// If we hit a #text token, collect it
		if ( '#text' === $token_type ) {
			$title_text .= $processor->get_modifiable_text();
		}
		// If we hit a closing tag, stop (end of title element)
		else if ( '#tag' === $token_type && $processor->is_tag_closer() && 'TITLE' === $processor->get_tag() ) {
			break;
		}
	}
	
	return $title_text;
}
