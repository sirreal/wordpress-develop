<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $link_stack = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $link_stack ) ) {
                    array_pop( $link_stack );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]      = array(
                    'href' => $href,
                    'text' => '',
                );
                $link_stack[] = count( $links ) - 1;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $link_stack ) ) {
            $current_index = $link_stack[ count( $link_stack ) - 1 ];
            $links[ $current_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
