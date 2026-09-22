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
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $chunk = '';
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $token_name = $processor->get_token_name();
            if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( '' === $chunk ) {
            continue;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $chunk_length <= $remaining ) {
            $text      .= $chunk;
            $remaining -= $chunk_length;
            continue;
        }

        $text .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
        break;
    }

    return $text;
}
