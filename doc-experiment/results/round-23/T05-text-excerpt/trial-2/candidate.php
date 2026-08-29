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

    $append_chunk = static function ( string $chunk ) use ( &$excerpt, &$remaining ): void {
        if ( $remaining <= 0 || '' === $chunk ) {
            return;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $chunk_length <= $remaining ) {
            $excerpt   .= $chunk;
            $remaining -= $chunk_length;
            return;
        }

        $excerpt   .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
        $remaining = 0;
    };

    while ( $remaining > 0 && $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $append_chunk( $processor->get_modifiable_text() );
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            continue;
        }

        $token_name = $processor->get_token_name();
        if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
            $append_chunk( $processor->get_modifiable_text() );
        }
    }

    return $excerpt;
}
