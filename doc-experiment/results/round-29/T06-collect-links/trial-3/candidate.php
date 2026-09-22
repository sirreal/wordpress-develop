<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $open_links = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_token_name() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $open_links );
            } else {
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

            continue;
        }

        if ( '#text' !== $processor->get_token_type() ) {
            continue;
        }

        for ( $i = count( $open_links ) - 1; $i >= 0; $i-- ) {
            if ( null !== $open_links[ $i ] ) {
                $links[ $open_links[ $i ] ]['text'] .= $processor->get_modifiable_text();
                break;
            }
        }
    }

    return $links;
}
