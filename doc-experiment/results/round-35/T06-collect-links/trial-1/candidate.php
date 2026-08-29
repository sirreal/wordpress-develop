<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $active_links = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $active_links );
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $links[]        = array(
                    'href' => $href,
                    'text' => '',
                );
                $active_links[] = count( $links ) - 1;
            } else {
                $active_links[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $active_links ) ) {
            $text = $processor->get_modifiable_text();
            foreach ( $active_links as $link_index ) {
                if ( null !== $link_index ) {
                    $links[ $link_index ]['text'] .= $text;
                }
            }
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
