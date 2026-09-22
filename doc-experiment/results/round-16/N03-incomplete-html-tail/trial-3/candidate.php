<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to reach the end of the input.
	while ( $processor->next_token() ) {
		// Continue scanning until we reach the end.
	}
	
	// Check if the processor paused because the document ended mid-token.
	return $processor->paused_at_incomplete_token();
}
