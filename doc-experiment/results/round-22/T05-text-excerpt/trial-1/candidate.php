<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';
    $remaining = $max_codepoints;

    $append_chunk = static function ( string $chunk ) use ( &$excerpt, &$remaining ): void {
        if ( '' === $chunk || $remaining <= 0 ) {
            return;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $length = mb_strlen( $chunk, 'UTF-8' );
            if ( $length <= $remaining ) {
                $excerpt .= $chunk;
                $remaining -= $length;
                return;
            }

            $excerpt   .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
            $remaining  = 0;
            return;
        }

        preg_match_all( '/./us', $chunk, $matches );
        $length = count( $matches[0] );
        if ( $length <= $remaining ) {
            $excerpt .= $chunk;
            $remaining -= $length;
            return;
        }

        $excerpt   .= implode( '', array_slice( $matches[0], 0, $remaining ) );
        $remaining  = 0;
    };

    while ( $remaining > 0 && $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $append_chunk( $processor->get_modifiable_text() );
            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $token_name = $processor->get_token_name();
        if ( 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
            $append_chunk( $processor->get_modifiable_text() );
        }
    }

    return $excerpt;
}
