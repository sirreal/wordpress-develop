<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links              = array();
    $current_link_index = null;
    $current_link_depth = null;

    while ( $processor->next_token() ) {
        if ( null !== $current_link_index && $processor->get_current_depth() < $current_link_depth ) {
            $current_link_index = null;
            $current_link_depth = null;
        }

        if ( 'A' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $href = $processor->get_attribute( 'href' );

            if ( is_string( $href ) ) {
                $links[] = array(
                    'href' => $href,
                    'text' => '',
                );

                $current_link_index = count( $links ) - 1;
                $current_link_depth = $processor->get_current_depth();
            }

            continue;
        }

        if ( null !== $current_link_index && '#text' === $processor->get_token_type() ) {
            $links[ $current_link_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $links;
}
