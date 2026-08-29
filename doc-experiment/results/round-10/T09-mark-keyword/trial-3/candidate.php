<?php

function mark_keyword( string $html, string $keyword ): string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $text = $processor->get_modifiable_text();
            
            // Check if the keyword (case-sensitive) is in the decoded text
            if ( str_contains( $text, $keyword ) ) {
                // Wrap the entire text node in a <mark> element
                $output .= '<mark>' . $processor->serialize_token() . '</mark>';
            } else {
                $output .= $processor->serialize_token();
            }
        } else {
            $output .= $processor->serialize_token();
        }
    }

    return $output;
}
