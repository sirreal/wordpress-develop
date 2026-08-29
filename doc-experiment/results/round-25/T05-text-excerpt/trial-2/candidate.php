<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $text   = '';
    $length = 0;

    while ( $processor->next_token() ) {
        $chunk = '';

        if ( '#text' === $processor->get_token_type() ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();
            if ( 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( '' === $chunk ) {
            continue;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $length + $chunk_length <= $max_codepoints ) {
            $text   .= $chunk;
            $length += $chunk_length;
            continue;
        }

        $text .= mb_substr( $chunk, 0, $max_codepoints - $length, 'UTF-8' );
        return $text;
    }

    return $text;
}
