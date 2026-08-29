<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( ! $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( 'A' ) ) {
        // Only process A tags that have an href attribute
        $href = $processor->get_attribute( 'href' );
        if ( null === $href ) {
            continue;
        }

        // Collect text content of the link
        $text = '';
        $depth_inside_link = $processor->get_current_depth();

        // Step through tokens inside the A tag to collect text
        while ( $processor->next_token() ) {
            $current_depth = $processor->get_current_depth();

            // Stop when we've exited the A tag
            if ( $current_depth < $depth_inside_link ) {
                break;
            }

            // Only collect text nodes inside the A tag itself (not in nested elements)
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
