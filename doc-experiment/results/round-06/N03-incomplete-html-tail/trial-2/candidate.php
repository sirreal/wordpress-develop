<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Try to parse all tokens in the document
	while ( $processor->next_token() ) {
		// Continue parsing tokens
	}
	
	// Check if the parser paused due to an incomplete token
	return $processor->paused_at_incomplete_token();
}
