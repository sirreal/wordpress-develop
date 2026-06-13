<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc          = array();
    $heading_tags = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();

        if ( null === $tag || ! in_array( $tag, $heading_tags, true ) ) {
            continue;
        }

        $text          = '';
        $heading_depth = $processor->get_current_depth();

        while ( $processor->next_token() && $processor->get_current_depth() >= $heading_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        $toc[] = array(
            'level' => (int) substr( $tag, 1 ),
            'text'  => $text,
        );
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
