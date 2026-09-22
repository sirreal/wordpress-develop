<?php
function html_text_excerpt( string $html, int $max_codepoints ): string {
    if ( $max_codepoints <= 0 ) {
        return '';
    }

    $processor = new WP_HTML_Tag_Processor( $html );
    $text      = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $text .= $processor->get_modifiable_text();
        } elseif ( '#tag' === $token_type ) {
            $token_name = $processor->get_token_name();
            if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text, 'UTF-8' ) > $max_codepoints ) {
                return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
            }
        }
    }

    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        return mb_strlen( $text, 'UTF-8' ) > $max_codepoints
            ? mb_substr( $text, 0, $max_codepoints, 'UTF-8' )
            : $text;
    }

    if ( preg_match_all( '/./us', $text, $matches ) <= $max_codepoints ) {
        return $text;
    }

    return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
}
