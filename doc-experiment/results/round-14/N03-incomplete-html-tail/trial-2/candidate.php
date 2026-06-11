<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Consume all tokens in the document
	while ( $processor->next_token() ) {
		// Continue until no more tokens are found
	}
	
	// Check if the processor paused at an incomplete token
	return $processor->paused_at_incomplete_token();
}
