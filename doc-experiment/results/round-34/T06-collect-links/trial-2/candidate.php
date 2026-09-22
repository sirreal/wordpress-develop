<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links         = array();
    $current_link  = null;
    $current_depth = 0;

    while ( $processor->next_token() ) {
        if ( null !== $current_link && '#text' === $processor->get_token_type() && $processor->get_current_depth() >= $current_depth ) {
            $current_link['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_link ) {
                    $links[]      = $current_link;
                    $current_link = null;
                }

                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $current_link  = array(
                    'href' => $href,
                    'text' => '',
                );
                $current_depth = $processor->get_current_depth();
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
