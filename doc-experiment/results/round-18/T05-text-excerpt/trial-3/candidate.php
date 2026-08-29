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
        $chunk = '';

        if ( '#text' === $processor->get_token_type() ) {
            $chunk = $processor->get_modifiable_text();
        } elseif (
            ! $processor->is_tag_closer() &&
            in_array( $processor->get_token_name(), array( 'TITLE', 'TEXTAREA' ), true )
        ) {
            $chunk = $processor->get_modifiable_text();
        }

        if ( '' === $chunk ) {
            continue;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $length + $chunk_length <= $max_codepoints ) {
            $excerpt .= $chunk;
            $length  += $chunk_length;
            continue;
        }

        $excerpt .= mb_substr( $chunk, 0, $max_codepoints - $length, 'UTF-8' );
        return $excerpt;
    }

    return $excerpt;
}
