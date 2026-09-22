<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $text      = '';
    $remaining = $max_codepoints;

    $append_text = static function ( string $chunk ) use ( &$text, &$remaining ): bool {
        if ( '' === $chunk || $remaining <= 0 ) {
            return $remaining <= 0;
        }

        $chunk_length = mb_strlen( $chunk, 'UTF-8' );
        if ( $chunk_length <= $remaining ) {
            $text      .= $chunk;
            $remaining -= $chunk_length;
            return false;
        }

        $text      .= mb_substr( $chunk, 0, $remaining, 'UTF-8' );
        $remaining = 0;
        return true;
    };

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null !== $processor ) {
        while ( $processor->next_token() ) {
            if ( '#text' === $processor->get_token_type() ) {
                if ( $append_text( $processor->get_modifiable_text() ) ) {
                    return $text;
                }
                continue;
            }

            if ( ! $processor->is_tag_closer() ) {
                $token_name = $processor->get_token_name();
                if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
                    if ( $append_text( $processor->get_modifiable_text() ) ) {
                        return $text;
                    }
                }
            }
        }

        if ( null === $processor->get_last_error() ) {
            return $text;
        }
    }

    $fallback = new WP_HTML_Tag_Processor( $html );
    while ( $fallback->next_token() ) {
        if ( '#text' === $fallback->get_token_type() ) {
            if ( $append_text( $fallback->get_modifiable_text() ) ) {
                break;
            }
            continue;
        }

        if ( ! $fallback->is_tag_closer() ) {
            $token_name = $fallback->get_token_name();
            if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
                if ( $append_text( $fallback->get_modifiable_text() ) ) {
                    break;
                }
            }
        }
    }

    return $text;
}
