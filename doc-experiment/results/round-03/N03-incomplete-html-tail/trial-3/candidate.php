<?php

function has_incomplete_html_tail( string $html ): bool {
    $processor = new WP_HTML_Tag_Processor( $html );
    
    // Scan through all tokens in the document
    while ( $processor->next_token() ) {
        // Continue scanning tokens
    }
    
    // If the parser paused because it reached the middle of a token, return true
    return $processor->paused_at_incomplete_token();
}
