<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $excerpt = '';
    $length  = 0;

    while ( $processor->next_token() ) {
        $text = '';

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();
        } elseif ( ! $processor->is_tag_closer() ) {
            $token_name = $processor->get_token_name();
            if ( 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
                $text = $processor->get_modifiable_text();
            }
        }

        if ( '' === $text ) {
            continue;
        }

        $remaining = $max_codepoints - $length;
        $text_len  = mb_strlen( $text, 'UTF-8' );

        if ( $text_len <= $remaining ) {
            $excerpt .= $text;
            $length  += $text_len;
            continue;
        }

        $excerpt .= mb_substr( $text, 0, $remaining, 'UTF-8' );
        break;
    }

    return $excerpt;
}
