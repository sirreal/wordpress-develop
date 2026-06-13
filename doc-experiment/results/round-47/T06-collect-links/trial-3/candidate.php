<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links   = array();
    $current = null;

    while ( $processor->next_token() ) {
        if ( 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current ) {
                    $links[] = $current;
                    $current = null;
                }
            } else {
                $href = $processor->get_attribute( 'href' );

                if ( is_string( $href ) ) {
                    $current = array(
                        'href' => $href,
                        'text' => '',
                    );
                }
            }

            continue;
        }

        if ( null !== $current && '#text' === $processor->get_token_type() ) {
            $current['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $links;
}
