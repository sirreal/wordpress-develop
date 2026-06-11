<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_token() ) {
		// Check if we've found a TITLE tag opening.
		if ( 'TITLE' === $processor->get_token_name() && ! $processor->is_tag_closer() ) {
			// The modifiable text of the TITLE element is already decoded.
			// This returns the title content with character references decoded.
			return $processor->get_modifiable_text();
		}
	}
	
	// No TITLE element was found.
	return null;
}
