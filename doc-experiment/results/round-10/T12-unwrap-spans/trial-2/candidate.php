<?php

function unwrap_spans( string $html ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( null === $processor ) {
        // If processor fails, return the input as-is
        return $html;
    }
    
    $output = '';
    
    while ( $processor->next_token() ) {
        $tag = $processor->get_tag();
        
        // Skip span opening and closing tags
        if ( 'SPAN' === $tag ) {
            continue;
        }
        
        // Serialize all other tokens
        $output .= $processor->serialize_token();
    }
    
    return $output;
}
