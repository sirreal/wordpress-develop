<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens until we reach the end
	while ( $processor->next_token() ) {
		// Continue scanning through all tokens
	}
	
	// Check if the processor paused because input ended in the middle of a syntax element
	return $processor->paused_at_incomplete_token();
}
