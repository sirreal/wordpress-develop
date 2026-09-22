<?php

function mark_keyword( string $html, string $keyword ): string {
    if ( '' === $keyword ) {
        $normalized = WP_HTML_Processor::normalize( $html );
        return null === $normalized ? $html : $normalized;
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return $html;
    }

    $output = '';

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();
            if ( str_contains( $text, $keyword ) ) {
                $output .= '<mark>' . $processor->serialize_token() . '</mark>';
                continue;
            }
        }

        $output .= $processor->serialize_token();
    }

    if ( null !== $processor->get_last_error() ) {
        return $html;
    }

    return $output;
}
