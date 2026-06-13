<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc = array();

    while ( $processor->next_tag() ) {
        $tag = $processor->get_tag();

        if ( null === $tag || ! in_array( $tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
            continue;
        }

        $level = (int) substr( $tag, 1 );
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

            $token_name = $processor->get_token_name();
            if ( in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
            return array();
        }

        $toc[] = array(
            'level' => $level,
            'text'  => $text,
        );
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
