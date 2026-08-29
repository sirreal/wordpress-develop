<?php

function get_document_title( string $html ): ?string {
	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_token() ) {
		if ( 'TITLE' === $processor->get_token_name() ) {
			return $processor->get_modifiable_text();
		}
	}

	return null;
}
