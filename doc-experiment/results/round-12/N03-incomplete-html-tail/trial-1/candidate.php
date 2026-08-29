<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Drain all tokens to scan to the end of the input
	while ( $processor->next_token() ) {
		continue;
	}
	
	// Check if the processor paused at an incomplete token
	return $processor->paused_at_incomplete_token();
}
