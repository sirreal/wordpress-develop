<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';
    $length    = 0;

    $append_limited = static function ( string $chunk ) use ( $max_codepoints, &$excerpt, &$length ): void {
        if ( '' === $chunk || $length >= $max_codepoints ) {
            return;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $chunk_length = mb_strlen( $chunk, 'UTF-8' );

            if ( $length + $chunk_length <= $max_codepoints ) {
                $excerpt .= $chunk;
                $length  += $chunk_length;
                return;
            }

            $remaining = $max_codepoints - $length;
            $excerpt  .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
            $length    = $max_codepoints;
            return;
        }

        if ( 1 === preg_match_all( '/./us', $chunk, $matches ) ) {
            $remaining = $max_codepoints - $length;
            $slice     = array_slice( $matches[0], 0, $remaining );
            $excerpt  .= implode( '', $slice );
            $length   += count( $slice );
        }
    };

    while ( $length < $max_codepoints && $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $append_limited( $processor->get_modifiable_text() );
            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $token_name = $processor->get_token_name();
        if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
            $append_limited( $processor->get_modifiable_text() );
        }
    }

    return $excerpt;
}
