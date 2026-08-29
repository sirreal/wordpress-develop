<?php

function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	// Scan through all tokens until we reach the end or hit an incomplete token.
	while ( $processor->next_token() ) {
		// Continue scanning.
	}
	
	// If the parser paused because it encountered an incomplete token at the end,
	// the document has an incomplete HTML tail.
	return $processor->paused_at_incomplete_token();
}
