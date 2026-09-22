<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( 'A' ) ) {
        // Get the href attribute
        $href = $processor->get_attribute( 'href' );

        // Skip A tags without href attribute
        if ( null === $href ) {
            continue;
        }

        // Record the depth of the A tag opener for tracking when we exit it
        $a_depth = $processor->get_current_depth();

        // Collect all text content inside this A element
        $text = '';
        while ( $processor->next_token() ) {
            $current_depth = $processor->get_current_depth();

            // Stop when we exit the A element (depth drops below A's depth)
            if ( $current_depth < $a_depth ) {
                break;
            }

            // Collect text from #text tokens that are direct or nested content
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        // Add the link to our results
        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    return $links;
}
