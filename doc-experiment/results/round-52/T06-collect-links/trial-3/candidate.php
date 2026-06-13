<?php

function collect_links( string $html ): array {
    if ( ! class_exists( 'WP_HTML_Processor' ) ) {
        return array();
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links  = array();
    $active = array();

    while ( $processor->next_token() ) {
        $depth = $processor->get_current_depth();

        while ( ! empty( $active ) ) {
            $top = $active[ count( $active ) - 1 ];

            if ( $depth >= $top['depth'] ) {
                break;
            }

            array_pop( $active );
        }

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            foreach ( $active as $context ) {
                $links[ $context['index'] ]['text'] .= $text;
            }

            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() || 'A' !== $processor->get_tag() ) {
            continue;
        }

        $href = $processor->get_attribute( 'href' );
        if ( ! is_string( $href ) ) {
            continue;
        }

        $links[]  = array(
            'href' => $href,
            'text' => '',
        );
        $active[] = array(
            'index' => count( $links ) - 1,
            'depth' => $depth,
        );
    }

    return $links;
}
