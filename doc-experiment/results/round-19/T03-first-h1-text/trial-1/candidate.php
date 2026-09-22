<?php

function get_first_h1_text( string $html ): ?string {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return null;
    }

    if ( ! $processor->next_tag( 'H1' ) ) {
        return null;
    }

    $depth = $processor->get_current_depth();
    $text  = '';

    while ( $processor->next_token() && $processor->get_current_depth() >= $depth ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag_name = $processor->get_token_name();

        if ( in_array( $tag_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOSCRIPT', 'NOEMBED', 'NOFRAME', 'NOFRAMES', 'XMP' ), true ) ) {
            $text .= $processor->get_modifiable_text();
        }
    }

    return $text;
}
