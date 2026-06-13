<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links  = array();
    $active = array();

    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            foreach ( $active as $open_link ) {
                $links[ $open_link['index'] ]['text'] .= $text;
            }

            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || 'A' !== $processor->get_tag() ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            $closer_depth = $processor->get_current_depth();

            while ( ! empty( $active ) ) {
                $last = $active[ count( $active ) - 1 ];
                if ( $last['depth'] !== $closer_depth + 1 ) {
                    break;
                }

                array_pop( $active );
            }

            continue;
        }

        $href = $processor->get_attribute( 'href' );
        if ( is_string( $href ) ) {
            $links[]  = array(
                'href' => $href,
                'text' => '',
            );
            $active[] = array(
                'depth' => $processor->get_current_depth(),
                'index' => count( $links ) - 1,
            );
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
