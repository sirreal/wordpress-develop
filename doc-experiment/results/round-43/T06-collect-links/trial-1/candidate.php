<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $anchor_map = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $anchor_map );
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]      = array(
                    'href' => $href,
                    'text' => '',
                );
                $anchor_map[] = count( $links ) - 1;
            } else {
                $anchor_map[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            foreach ( $anchor_map as $link_index ) {
                if ( null !== $link_index ) {
                    $links[ $link_index ]['text'] .= $text;
                }
            }
        }
    }

    return $links;
}
