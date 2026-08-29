<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();
    $open_anchors = array();

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            $text = $processor->get_modifiable_text();

            foreach ( $open_anchors as $anchor ) {
                if ( null !== $anchor['index'] ) {
                    $links[ $anchor['index'] ]['text'] .= $text;
                }
            }

            continue;
        }

        if ( '#tag' !== $token_type ) {
            continue;
        }

        if ( 'A' !== $processor->get_tag() ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( $open_anchors ) {
                array_pop( $open_anchors );
            }
            continue;
        }

        $href = $processor->get_attribute( 'href' );
        $index = null;

        if ( is_string( $href ) ) {
            $index = count( $links );
            $links[ $index ] = array(
                'href' => $href,
                'text' => '',
            );
        }

        $open_anchors[] = array(
            'index' => $index,
        );
    }

    return array_values( $links );
}