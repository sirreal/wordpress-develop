<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_level = null;
    $current_text  = '';
    $heading_depth = 0;

    while ( $processor->next_token() ) {
        if ( null !== $current_level && $processor->get_current_depth() < $heading_depth ) {
            $toc[] = array(
                'level' => $current_level,
                'text'  => $current_text,
            );

            $current_level = null;
            $current_text  = '';
            $heading_depth = 0;
        }

        if ( null !== $current_level ) {
            if ( '#text' === $processor->get_token_type() ) {
                $current_text .= $processor->get_modifiable_text();
            } elseif ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                $current_text .= $processor->get_modifiable_text();
            }
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || 2 !== strlen( $tag ) || 'H' !== $tag[0] || $tag[1] < '1' || $tag[1] > '6' ) {
            continue;
        }

        $current_level = (int) $tag[1];
        $current_text  = '';
        $heading_depth = $processor->get_current_depth();
    }

    if ( null !== $current_level ) {
        $toc[] = array(
            'level' => $current_level,
            'text'  => $current_text,
        );
    }

    return $toc;
}
