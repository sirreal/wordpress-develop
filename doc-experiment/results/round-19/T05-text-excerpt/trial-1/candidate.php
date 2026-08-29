<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';
    $count     = 0;

    while ( $processor->next_token() ) {
        $text = '';
        $type = $processor->get_token_type();

        if ( '#text' === $type ) {
            $text = $processor->get_modifiable_text();
        } elseif ( '#tag' === $type ) {
            $name = $processor->get_token_name();
            if ( 'TITLE' === $name || 'TEXTAREA' === $name ) {
                $text = $processor->get_modifiable_text();
            }
        }

        if ( '' === $text ) {
            continue;
        }

        $length = mb_strlen( $text, 'UTF-8' );
        if ( $count + $length <= $max_codepoints ) {
            $excerpt .= $text;
            $count   += $length;
            continue;
        }

        $excerpt .= mb_substr( $text, 0, $max_codepoints - $count, 'UTF-8' );
        break;
    }

    return $excerpt;
}
