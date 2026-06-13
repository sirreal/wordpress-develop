<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $active_links = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $active_links ) ) {
                    $links[] = array_pop( $active_links );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $active_links[] = array(
                    'href' => $href,
                    'text' => '',
                );
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $active_links ) ) {
            $active_links[ count( $active_links ) - 1 ]['text'] .= $processor->get_modifiable_text();
        }
    }

    while ( ! empty( $active_links ) ) {
        $links[] = array_pop( $active_links );
    }

    return $links;
}
