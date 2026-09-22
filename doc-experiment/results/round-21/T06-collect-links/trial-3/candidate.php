<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $active_links = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
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
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();
            foreach ( $active_links as $index ) {
                $links[ $index ]['text'] .= $text;
            }
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
