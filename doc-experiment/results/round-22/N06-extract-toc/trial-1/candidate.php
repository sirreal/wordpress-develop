<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current       = null;
    $heading_depth = 0;

    while ( $processor->next_token() ) {
        if ( null !== $current && $processor->get_current_depth() < $heading_depth ) {
            $toc[]         = $current;
            $current       = null;
            $heading_depth = 0;
        }

        if ( '#tag' === $processor->get_token_type() ) {
            if ( $processor->is_tag_closer() ) {
                continue;
            }

            $tag = $processor->get_tag();
            if ( null !== $tag && 2 === strlen( $tag ) && 'H' === $tag[0] && $tag[1] >= '1' && $tag[1] <= '6' ) {
                if ( null !== $current ) {
                    $toc[] = $current;
                }

                $current = array(
                    'level' => (int) $tag[1],
                    'text'  => '',
                );
                $heading_depth = $processor->get_current_depth();
            }

            continue;
        }

        if ( null !== $current && '#text' === $processor->get_token_type() ) {
            $current['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current ) {
        $toc[] = $current;
    }

    return $toc;
}
