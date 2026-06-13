<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links   = array();
    $a_stack = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $a_stack ) ) {
                    array_pop( $a_stack );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]  = array(
                    'href' => $href,
                    'text' => '',
                );
                $a_stack[] = count( $links ) - 1;
            } else {
                $a_stack[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $a_stack ) ) {
            $text = $processor->get_modifiable_text();

            foreach ( $a_stack as $link_index ) {
                if ( null !== $link_index ) {
                    $links[ $link_index ]['text'] .= $text;
                }
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
