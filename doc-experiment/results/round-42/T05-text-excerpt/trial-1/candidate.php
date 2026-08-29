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
        if ( '#text' === $processor->get_token_type() ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $tag_name = $processor->get_token_name();
            if ( 'TEXTAREA' === $tag_name || 'TITLE' === $tag_name ) {
                $text .= $processor->get_modifiable_text();
            }
        }
    }

    if ( preg_match( '/^.{0,' . $max_codepoints . '}/us', $text, $matches ) ) {
        return $matches[0];
    }

    return '';
}
