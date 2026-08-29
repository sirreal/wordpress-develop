<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );

	// Scan through all tokens in the document.
	while ( $processor->next_token() ) {
		// Continue scanning.
	}

	// If the processor paused at an incomplete token, the tail is incomplete.
	return $processor->paused_at_incomplete_token();
}
