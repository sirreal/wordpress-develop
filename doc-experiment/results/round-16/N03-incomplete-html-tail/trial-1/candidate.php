<?php
function has_incomplete_html_tail( string $html ): bool {
	$processor = new WP_HTML_Tag_Processor( $html );
	
	while ( $processor->next_token() ) {
		// Scan all tokens.
	}
	
	return $processor->paused_at_incomplete_token();
}
