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
        } elseif ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_tag();

            if ( 'TITLE' === $tag_name || 'TEXTAREA' === $tag_name ) {
                $text .= $processor->get_modifiable_text();
            }
        }
    }

    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
    }

    preg_match_all( '/./us', $text, $matches );

    return implode( '', array_slice( $matches[0], 0, $max_codepoints ) );
}
