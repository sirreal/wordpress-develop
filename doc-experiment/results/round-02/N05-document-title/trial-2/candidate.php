<?php

function get_document_title( string $html ): ?string {
	$processor = WP_HTML_Processor::create_full_parser( $html );
	
	if ( ! $processor ) {
		return null;
	}
	
	while ( $processor->next_token() ) {
		if ( 'TITLE' === $processor->get_token_name() ) {
			return $processor->get_modifiable_text();
		}
	}
	
	return null;
}
