<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links      = array();
    $open_links = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $open_links ) ) {
                    array_pop( $open_links );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[] = array(
                    'href' => $href,
                    'text' => '',
                );
                $open_links[] = count( $links ) - 1;
            } else {
                $open_links[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $open_links ) ) {
            $text = $processor->get_modifiable_text();
            if ( '' === $text ) {
                continue;
            }

            foreach ( $open_links as $link_index ) {
                if ( null !== $link_index ) {
                    $links[ $link_index ]['text'] .= $text;
                }
            }
        }
    }

    if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
        return array();
    }

    return $links;
}
