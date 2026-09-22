<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
        // Check if the A tag has an href attribute
        $href = $processor->get_attribute( 'href' );
        if ( null === $href ) {
            // Skip A tags without href attribute
            continue;
        }

        // Collect text content from inside the A element
        $text = '';
        $depth_inside_a = $processor->get_current_depth();

        while ( $processor->next_token() && $processor->get_current_depth() >= $depth_inside_a ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        // Add this link to the results
        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    return $links;
}
