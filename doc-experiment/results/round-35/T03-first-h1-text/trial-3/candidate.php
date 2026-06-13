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

        if ( $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if (
            'SCRIPT' === $tag ||
            'STYLE' === $tag ||
            'TEXTAREA' === $tag ||
            'TITLE' === $tag
        ) {
            $text .= $processor->get_modifiable_text();
        }
    }

    return $text;
}
