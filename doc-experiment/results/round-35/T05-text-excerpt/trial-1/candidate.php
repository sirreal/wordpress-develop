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

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $token_name = $processor->get_token_name();
            if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
                $text .= $processor->get_modifiable_text();
            }
        }
    }

    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
    }

    if ( preg_match_all( '/./us', $text, $matches ) ) {
        return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
    }

    return '';
}
