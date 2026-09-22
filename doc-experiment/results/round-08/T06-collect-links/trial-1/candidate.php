<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( ! $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( 'A' ) ) {
        // Check if the A tag has an href attribute
        $href = $processor->get_attribute( 'href' );
        if ( null === $href ) {
            continue;
        }

        // Record the depth to collect text inside this A element
        $link_depth = $processor->get_current_depth();
        $text = '';

        // Walk through tokens inside the A element to collect text
        while ( $processor->next_token() && $processor->get_current_depth() >= $link_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    return $links;
}
