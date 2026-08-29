<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;
    $heading_tags    = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    while ( $processor->next_token() ) {
        $tag = $processor->get_tag();

        if ( null !== $tag && isset( $heading_tags[ $tag ] ) ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading ) {
                    $toc[]           = $current_heading;
                    $current_heading = null;
                }
            } else {
                $current_heading = array(
                    'level' => $heading_tags[ $tag ],
                    'text'  => '',
                );
            }

            continue;
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            $token_name = $processor->get_token_name();
            if ( null !== $token_name && ! $processor->is_tag_closer() && in_array( $token_name, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' ), true ) ) {
                $current_heading['text'] .= $processor->get_modifiable_text();
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
