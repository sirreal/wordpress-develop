<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $open_links = array();

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            if ( 'A' === $processor->get_tag() ) {
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

            if ( ! $processor->is_tag_closer() ) {
                $text = $processor->get_modifiable_text();
                if ( '' !== $text ) {
                    foreach ( $open_links as $link_index ) {
                        if ( null !== $link_index ) {
                            $links[ $link_index ]['text'] .= $text;
                        }
                    }
                }
            }

            continue;
        }

        if ( '#text' === $token_type ) {
            $text = $processor->get_modifiable_text();
            if ( '' !== $text ) {
                foreach ( $open_links as $link_index ) {
                    if ( null !== $link_index ) {
                        $links[ $link_index ]['text'] .= $text;
                    }
                }
            }
        }
    }

    return $links;
}
