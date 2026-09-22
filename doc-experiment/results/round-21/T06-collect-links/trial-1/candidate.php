<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $open_indices = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $open_indices );
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]       = array(
                    'href' => $href,
                    'text' => '',
                );
                $open_indices[] = count( $links ) - 1;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $open_indices ) ) {
            $text = $processor->get_modifiable_text();
            foreach ( $open_indices as $index ) {
                $links[ $index ]['text'] .= $text;
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
