<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Consume all tokens. When next_token() returns false, the processor
	// may have paused at an incomplete token if one exists at the end.
	while ( $processor->next_token() ) {
		// Continue parsing tokens until we reach the end.
	}
	
	// Check if the parser paused because the input ended in the middle
	// of a syntax element (incomplete token).
	return $processor->paused_at_incomplete_token();
}
