<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $remaining = $max_codepoints;
    $excerpt   = '';

    $append_chunk = static function ( string $chunk ) use ( &$excerpt, &$remaining ): bool {
        if ( '' === $chunk || $remaining <= 0 ) {
            return $remaining <= 0;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $chunk_length <= $remaining ) {
            $excerpt   .= $chunk;
            $remaining -= $chunk_length;
            return 0 === $remaining;
        }

        $excerpt   .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
        $remaining = 0;
        return true;
    };

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            if ( $append_chunk( $processor->get_modifiable_text() ) ) {
                break;
            }

            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();
            if ( 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name ) {
                if ( $append_chunk( $processor->get_modifiable_text() ) ) {
                    break;
                }
            }
        }
    }

    return $excerpt;
}
