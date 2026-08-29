<?php

function mark_keyword( string $html, string $keyword ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $output = '';
    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        
        // Check if this is a text node
        if ( '#text' === $token_type ) {
            $text = $processor->get_modifiable_text();
            
            // Check if the decoded text contains the keyword (case-sensitive substring match)
            if ( false !== strpos( $text, $keyword ) ) {
                // Wrap entire text node in <mark> tags
                $output .= '<mark>' . $processor->serialize_token() . '</mark>';
            } else {
                // Text doesn't contain keyword, serialize normally
                $output .= $processor->serialize_token();
            }
        } else {
            // For non-text tokens, serialize normally
            $output .= $processor->serialize_token();
        }
    }
    
    return $output;
}
