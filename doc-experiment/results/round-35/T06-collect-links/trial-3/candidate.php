<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links         = array();
    $anchor_stack  = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $anchor_stack ) ) {
                    array_pop( $anchor_stack );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );

            if ( is_string( $href ) ) {
                $links[] = array(
                    'href' => $href,
                    'text' => '',
                );
                $anchor_stack[] = count( $links ) - 1;
            } else {
                $anchor_stack[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $anchor_stack ) ) {
            $text = $processor->get_modifiable_text();

            foreach ( $anchor_stack as $link_index ) {
                if ( null !== $link_index ) {
                    $links[ $link_index ]['text'] .= $text;
                }
            }
        }
    }

    return $links;
}
