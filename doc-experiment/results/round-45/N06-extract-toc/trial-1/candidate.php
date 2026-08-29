<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $levels = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );
    $toc = array();

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();

        if ( null === $tag || ! isset( $levels[ $tag ] ) ) {
            continue;
        }

        $heading_depth = $processor->get_current_depth();
        $text          = '';

        while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        $toc[] = array(
            'level' => $levels[ $tag ],
            'text'  => $text,
        );
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
