<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $chunk = '';
        $type  = $processor->get_token_type();

        if ( '#text' === $type ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $type && ! $processor->is_tag_closer() ) {
            $tag = $processor->get_tag();
            if ( 'TITLE' === $tag || 'TEXTAREA' === $tag ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( '' === $chunk ) {
            continue;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $chunk_length <= $remaining ) {
            $excerpt   .= $chunk;
            $remaining -= $chunk_length;
            continue;
        }

        $excerpt .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
        break;
    }

    return $excerpt;
}
