<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $open_links = array();

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();
            if ( '' !== $text ) {
                foreach ( $open_links as $link_index ) {
                    if ( null !== $link_index ) {
                        $links[ $link_index ]['text'] .= $text;
                    }
                }
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            array_pop( $open_links );
            continue;
        }

        $href = $processor->get_attribute( 'href' );
        if ( is_string( $href ) ) {
            $links[]      = array(
                'href' => $href,
                'text' => '',
            );
            $open_links[] = count( $links ) - 1;
        } else {
            $open_links[] = null;
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
