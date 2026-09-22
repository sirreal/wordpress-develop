<?php

function get_first_h1_text( string $html ): ?string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return null;
    }

    if ( ! $processor->next_tag( 'H1' ) ) {
        return null;
    }

    $h1_depth = $processor->get_current_depth();
    $text     = '';

    while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $token_name = $processor->get_token_name();
        if (
            'SCRIPT' === $token_name ||
            'STYLE' === $token_name ||
            'TEXTAREA' === $token_name ||
            'TITLE' === $token_name
        ) {
            $text .= $processor->get_modifiable_text();
        }
    }

    return $text;
}
