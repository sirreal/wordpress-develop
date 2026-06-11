<?php

function has_incomplete_html_tail( string $html ): bool {
    $processor = new WP_HTML_Tag_Processor( $html );
    
    // Scan through all tokens to reach the end of the document
    while ( $processor->next_token() ) {
        // Continue until we've processed all tokens
    }
    
    // Check if the processor paused due to an incomplete token
    return $processor->paused_at_incomplete_token();
}
