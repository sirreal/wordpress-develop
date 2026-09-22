<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return '';
    }

    $excerpt   = '';
    $remaining = $max_codepoints;

    while ( $remaining > 0 && $processor->next_token() ) {
        $text = '';

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();
        } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $token_name = $processor->get_token_name();
            if ( 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
                $text = $processor->get_modifiable_text();
            }
        }

        if ( '' === $text ) {
            continue;
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            $length = mb_strlen( $text, 'UTF-8' );
            if ( $length <= $remaining ) {
                $excerpt   .= $text;
                $remaining -= $length;
            } else {
                $excerpt .= mb_substr( $text, 0, $remaining, 'UTF-8' );
                break;
            }

            continue;
        }

        $codepoints = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( false === $codepoints || array() === $codepoints ) {
            continue;
        }

        $length = count( $codepoints );
        if ( $length <= $remaining ) {
            $excerpt   .= $text;
            $remaining -= $length;
        } else {
            $excerpt .= implode( '', array_slice( $codepoints, 0, $remaining ) );
            break;
        }
    }

    return $excerpt;
}
