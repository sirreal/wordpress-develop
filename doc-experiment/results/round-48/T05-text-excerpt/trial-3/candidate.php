<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $text = '';

    while ( $processor->next_token() ) {
        $piece = '';

        if ( '#text' === $processor->get_token_type() ) {
            $piece = $processor->get_modifiable_text();
        } elseif ( ! $processor->is_tag_closer() ) {
            $token_name = $processor->get_token_name();
            if ( 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
                $piece = $processor->get_modifiable_text();
            }
        }

        if ( '' === $piece ) {
            continue;
        }

        $remaining = $max_codepoints - mb_strlen( $text, 'UTF-8' );
        if ( $remaining <= 0 ) {
            break;
        }

        if ( mb_strlen( $piece, 'UTF-8' ) <= $remaining ) {
            $text .= $piece;
            continue;
        }

        $text .= mb_substr( $piece, 0, $remaining, 'UTF-8' );
        break;
    }

    return $text;
}
