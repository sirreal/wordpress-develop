<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $results = array();
    $stack   = array();

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( ! empty( $stack ) ) {
                    $results[] = array(
                        'href' => $stack[ count( $stack ) - 1 ]['href'],
                        'text' => $stack[ count( $stack ) - 1 ]['text'],
                    );
                    array_pop( $stack );
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $stack[] = array(
                    'href' => $href,
                    'text' => '',
                );
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $stack ) ) {
            $text = $processor->get_modifiable_text();
            $last = count( $stack ) - 1;
            $stack[ $last ]['text'] .= $text;
        }
    }

    return $results;
}
