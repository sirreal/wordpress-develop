<?php

function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $excerpt    = '';
    $codepoints = 0;
    $append_text = static function ( string $text ) use ( &$excerpt, &$codepoints, $max_codepoints ): bool {
        if ( '' === $text ) {
            return false;
        }

        $remaining = $max_codepoints - $codepoints;
        if ( $remaining <= 0 ) {
            return true;
        }

        $length = mb_strlen( $text, 'UTF-8' );
        if ( $length <= $remaining ) {
            $excerpt    .= $text;
            $codepoints += $length;
            return $codepoints >= $max_codepoints;
        }

        $excerpt    .= mb_substr( $text, 0, $remaining, 'UTF-8' );
        $codepoints += $remaining;
        return true;
    };

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null !== $processor ) {
        while ( $processor->next_token() ) {
            $token_type = $processor->get_token_type();

            if ( '#text' === $token_type ) {
                if ( $append_text( $processor->get_modifiable_text() ) ) {
                    return $excerpt;
                }
                continue;
            }

            if (
                '#tag' === $token_type &&
                ! $processor->is_tag_closer() &&
                in_array( $processor->get_token_name(), array( 'TITLE', 'TEXTAREA' ), true )
            ) {
                if ( $append_text( $processor->get_modifiable_text() ) ) {
                    return $excerpt;
                }
            }
        }

        if ( null === $processor->get_last_error() ) {
            return $excerpt;
        }
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            if ( $append_text( $processor->get_modifiable_text() ) ) {
                break;
            }
            continue;
        }

        if (
            '#tag' === $token_type &&
            ! $processor->is_tag_closer() &&
            in_array( $processor->get_token_name(), array( 'TITLE', 'TEXTAREA' ), true )
        ) {
            if ( $append_text( $processor->get_modifiable_text() ) ) {
                break;
            }
        }
    }

    return $excerpt;
}
