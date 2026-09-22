<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $text  = '';
    $count = 0;

    while ( $processor->next_token() ) {
        $chunk = '';
        $type  = $processor->get_token_type();

        if ( '#text' === $type ) {
            $chunk = $processor->get_modifiable_text();
        } elseif ( '#tag' === $type && ! $processor->is_tag_closer() ) {
            $name = $processor->get_token_name();
            if ( 'TEXTAREA' === $name || 'TITLE' === $name ) {
                $chunk = $processor->get_modifiable_text();
            }
        }

        if ( '' === $chunk ) {
            continue;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $count + $chunk_length <= $max_codepoints ) {
            $text  .= $chunk;
            $count += $chunk_length;
            continue;
        }

        $text .= mb_substr( $chunk, 0, $max_codepoints - $count, 'UTF-8' );
        return $text;
    }

    return $text;
}
