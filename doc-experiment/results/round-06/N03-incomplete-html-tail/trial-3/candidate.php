<?php

function has_incomplete_html_tail( string $html ): bool {
    $processor = new WP_HTML_Tag_Processor( $html );
    
    // Try to find any tag - this will consume the document and pause if incomplete
    $processor->next_tag();
    
    // If paused at an incomplete token, the tail is incomplete
    return $processor->paused_at_incomplete_token();
}
