<?php

function get_first_h1_text( string $html ): ?string {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return null;
    }

    if ( ! $processor->next_tag( 'H1' ) ) {
        return null;
    }

    $text            = '';
    $h1_depth        = $processor->get_current_depth();
    $text_only_tags  = array(
        'IFRAME',
        'NOEMBED',
        'NOFRAME',
        'NOFRAMES',
        'NOSCRIPT',
        'SCRIPT',
        'STYLE',
        'TEXTAREA',
        'TITLE',
        'XMP',
    );

    while ( $processor->next_token() && $processor->get_current_depth() >= $h1_depth ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        if ( in_array( $processor->get_tag(), $text_only_tags, true ) ) {
            $text .= $processor->get_modifiable_text();
        }
    }

    return $text;
}
