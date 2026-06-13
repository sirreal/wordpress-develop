<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $anchor_stack = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $anchor_stack );
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]        = array(
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
            $current_link = end( $anchor_stack );
            if ( null !== $current_link ) {
                $links[ $current_link ]['text'] .= $processor->get_modifiable_text();
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
