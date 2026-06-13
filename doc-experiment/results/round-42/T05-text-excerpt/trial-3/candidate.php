<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $excerpt   = '';
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $text = '';
        $type = $processor->get_token_type();

        if ( '#text' === $type ) {
            $text = $processor->get_modifiable_text();
        } elseif ( '#tag' === $type && ! $processor->is_tag_closer() ) {
            $name = $processor->get_token_name();
            if ( 'TITLE' === $name || 'TEXTAREA' === $name ) {
                $text = $processor->get_modifiable_text();
            }
        }

        if ( '' === $text ) {
            continue;
        }

        $length = mb_strlen( $text, 'UTF-8' );
        if ( $length <= $remaining ) {
            $excerpt .= $text;
            $remaining -= $length;
        } else {
            $excerpt .= mb_substr( $text, 0, $remaining, 'UTF-8' );
            break;
        }
    }

    return $excerpt;
}
