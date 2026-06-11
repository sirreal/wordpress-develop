<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Try to find any tag in the HTML
	while ( $processor->next_token() ) {
		// Keep advancing through all tokens
	}
	
	// If the processor paused because it encountered an incomplete token, return true
	return $processor->paused_at_incomplete_token();
}
