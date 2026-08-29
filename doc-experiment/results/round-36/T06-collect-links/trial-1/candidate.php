<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $link_stack = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $link_stack ) ) {
                    array_pop( $link_stack );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]      = array(
                    'href' => $href,
                    'text' => '',
                );
                $link_stack[] = count( $links ) - 1;
            } else {
                $link_stack[] = null;
            }

            continue;
        }

        if ( '#text' !== $processor->get_token_type() || empty( $link_stack ) ) {
            continue;
        }

        for ( $i = count( $link_stack ) - 1; $i >= 0; $i-- ) {
            if ( null !== $link_stack[ $i ] ) {
                $links[ $link_stack[ $i ] ]['text'] .= $processor->get_modifiable_text();
                break;
            }
        }
    }

    return $links;
}
