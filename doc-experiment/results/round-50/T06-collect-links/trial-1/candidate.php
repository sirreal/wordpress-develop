<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links              = array();
    $current_link_index = null;

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                $current_link_index = null;
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[] = array(
                    'href' => $href,
                    'text' => '',
                );
                $current_link_index = count( $links ) - 1;
            } else {
                $current_link_index = null;
            }

            continue;
        }

        if ( null !== $current_link_index && '#text' === $processor->get_token_type() ) {
            $links[ $current_link_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $links;
}
