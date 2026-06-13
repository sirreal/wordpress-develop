<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links                = array();
    $anchor_stack         = array();
    $active_link_indexes  = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                $link_index = array_pop( $anchor_stack );
                if ( null !== $link_index ) {
                    array_pop( $active_link_indexes );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[] = array(
                    'href' => $href,
                    'text' => '',
                );

                $link_index            = count( $links ) - 1;
                $anchor_stack[]        = $link_index;
                $active_link_indexes[] = $link_index;
            } else {
                $anchor_stack[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $active_link_indexes ) ) {
            $text = $processor->get_modifiable_text();

            foreach ( $active_link_indexes as $link_index ) {
                $links[ $link_index ]['text'] .= $text;
            }
        }
    }

    return $links;
}
