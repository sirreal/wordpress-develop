<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $results = array();
    $stack   = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                array_pop( $stack );
                continue;
            }

            $href = $processor->get_attribute( 'href' );

            if ( is_string( $href ) ) {
                $results[] = array(
                    'href' => $href,
                    'text' => '',
                );

                $stack[] = count( $results ) - 1;
            } else {
                $stack[] = null;
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $text = $processor->get_modifiable_text();

            foreach ( $stack as $result_index ) {
                if ( null !== $result_index ) {
                    $results[ $result_index ]['text'] .= $text;
                }
            }
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $results;
}
