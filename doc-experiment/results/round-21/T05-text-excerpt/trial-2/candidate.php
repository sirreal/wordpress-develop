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
            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $token_name = $processor->get_token_name();
        if ( 'TITLE' === $token_name || 'TEXTAREA' === $token_name ) {
            $text .= $processor->get_modifiable_text();
        }
    }

    if ( mb_strlen( $text, 'UTF-8' ) <= $max_codepoints ) {
        return $text;
    }

    return mb_substr( $text, 0, $max_codepoints, 'UTF-8' );
}
