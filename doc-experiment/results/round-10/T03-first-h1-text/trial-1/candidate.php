<?php

function get_first_h1_text( string $html ): ?string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    // Find the first H1 element
    if ( ! $processor->next_tag( array( 'tag_name' => 'H1' ) ) ) {
        return null;
    }
    
    // Record the depth of the H1 element
    $h1_depth = $processor->get_current_depth();
    
    // Collect text content
    $text = '';
    
    while ( $processor->next_token() ) {
        $current_depth = $processor->get_current_depth();
        
        // Stop when we exit the H1 element (depth drops below h1_depth)
        if ( $current_depth < $h1_depth ) {
            break;
        }
        
        // Collect text nodes that are inside the H1
        if ( '#text' === $processor->get_token_type() && $current_depth >= $h1_depth ) {
            $text .= $processor->get_modifiable_text();
        }
    }
    
    // Return empty string if no text was found (as per spec, not null)
    return $text;
}
