<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';
    $length    = 0;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#text' === $token_type ) {
            $text = $processor->get_modifiable_text();
        } elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() && ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) ) {
            $text = $processor->get_modifiable_text();
        } else {
            continue;
        }

        if ( '' === $text ) {
            continue;
        }

        $text_length = mb_strlen( $text, 'UTF-8' );

        if ( $length + $text_length <= $max_codepoints ) {
            $excerpt .= $text;
            $length  += $text_length;
            continue;
        }

        $excerpt .= mb_substr( $text, 0, $max_codepoints - $length, 'UTF-8' );
        return $excerpt;
    }

    return $excerpt;
}
