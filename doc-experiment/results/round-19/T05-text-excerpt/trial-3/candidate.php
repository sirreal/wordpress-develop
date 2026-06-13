<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $excerpt   = '';

    $append_text = static function ( string $text ) use ( &$excerpt, $max_codepoints ): bool {
        if ( '' === $text ) {
            return false;
        }

        $current_length = mb_strlen( $excerpt, 'UTF-8' );
        $remaining      = $max_codepoints - $current_length;

        if ( $remaining <= 0 ) {
            return true;
        }

        $text_length = mb_strlen( $text, 'UTF-8' );
        if ( $text_length <= $remaining ) {
            $excerpt .= $text;
            return false;
        }

        $excerpt .= mb_substr( $text, 0, $remaining, 'UTF-8' );
        return true;
    };

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            if ( $append_text( $processor->get_modifiable_text() ) ) {
                break;
            }

            continue;
        }

        $token_name = $processor->get_token_name();
        if ( 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
            if ( $append_text( $processor->get_modifiable_text() ) ) {
                break;
            }
        }
    }

    return $excerpt;
}
