<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Try to consume all tokens until we hit the end or an incomplete token
	while ( $processor->next_token() ) {
		// Continue scanning through all tokens
	}
	
	// If the processor paused at an incomplete token, the HTML is truncated
	return $processor->paused_at_incomplete_token();
}
