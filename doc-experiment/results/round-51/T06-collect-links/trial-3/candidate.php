<?php
function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links        = array();
    $current_link = null;

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'A' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_link ) {
                    $links[]      = $current_link;
                    $current_link = null;
                }
                continue;
            }

            $href = $processor->get_attribute( 'href' );
            if ( is_string( $href ) ) {
                $current_link = array(
                    'href' => $href,
                    'text' => '',
                );
            }

            continue;
        }

        if ( null !== $current_link && '#text' === $processor->get_token_type() ) {
            $current_link['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() ) {
        return array();
    }

    return $links;
}
