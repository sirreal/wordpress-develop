<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();
    $stack = array();

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                $entry = array_pop( $stack );
                if ( is_array( $entry ) ) {
                    $links[] = $entry;
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            $stack[] = is_string( $href )
                ? array(
                    'href' => $href,
                    'text' => '',
                )
                : null;

            continue;
        }

        if ( '#text' === $processor->get_token_type() && ! empty( $stack ) ) {
            $last_index = count( $stack ) - 1;
            if ( is_array( $stack[ $last_index ] ) ) {
                $stack[ $last_index ]['text'] .= $processor->get_modifiable_text();
            }
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
