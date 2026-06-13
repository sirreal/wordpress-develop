<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $scan = static function ( $processor, int $limit ): string {
        $excerpt = '';
        $length  = 0;

        while ( $processor->next_token() ) {
            $chunk = '';

            if ( '#text' === $processor->get_token_type() ) {
                $chunk = $processor->get_modifiable_text();
            } else {
                $token_name = $processor->get_token_name();
                if ( ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) && ! $processor->is_tag_closer() ) {
                    $chunk = $processor->get_modifiable_text();
                }
            }

            if ( '' === $chunk ) {
                continue;
            }

            $remaining = $limit - $length;
            if ( $remaining <= 0 ) {
                break;
            }

            $chunk_length = mb_strlen( $chunk, 'UTF-8' );
            if ( $chunk_length <= $remaining ) {
                $excerpt .= $chunk;
                $length  += $chunk_length;
                continue;
            }

            $excerpt .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
            return $excerpt;
        }

        return $excerpt;
    };

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null !== $processor ) {
        $excerpt = $scan( $processor, $max_codepoints );
        if ( mb_strlen( $excerpt, 'UTF-8' ) >= $max_codepoints || null === $processor->get_last_error() ) {
            return $excerpt;
        }
    }

    return $scan( new WP_HTML_Tag_Processor( $html ), $max_codepoints );
}
