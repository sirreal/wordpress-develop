<?php

function mark_keyword( string $html, string $keyword ): string {
    // Use WP_HTML_Processor for full HTML parsing with structure awareness
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( null === $processor ) {
        // If parsing fails, return normalized form or empty string
        $normalized = WP_HTML_Processor::normalize( $html );
        return $normalized ?? '';
    }
    
    $output = '';
    
    // Iterate through all tokens in the document
    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        
        if ( '#text' === $token_type ) {
            // Get the decoded text content of this text node
            $text = $processor->get_modifiable_text();
            
            // Check if the keyword (case-sensitive) appears in the decoded text
            if ( str_contains( $text, $keyword ) ) {
                // Wrap the entire text node in a <mark> element
                // serialize_token() gives us the normalized/encoded version
                $output .= '<mark>' . $processor->serialize_token() . '</mark>';
            } else {
                // No keyword match, just add the serialized token as-is
                $output .= $processor->serialize_token();
            }
        } else {
            // For all other token types (tags, comments, etc.), serialize as-is
            $output .= $processor->serialize_token();
        }
    }
    
    return $output;
}
