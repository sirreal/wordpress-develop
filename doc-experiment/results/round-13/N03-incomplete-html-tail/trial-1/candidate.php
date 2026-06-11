<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens to reach the end of the document.
	while ( $processor->next_token() ) {
		continue;
	}
	
	// Check if the parser paused because input ended in the middle of a token.
	return $processor->paused_at_incomplete_token();
}
