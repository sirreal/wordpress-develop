<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc                = array();
    $current_heading    = null;
    $current_heading_id = null;
    $heading_tags       = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && in_array( $tag, $heading_tags, true ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_heading && $tag === $current_heading_id ) {
                        $toc[]            = $current_heading;
                        $current_heading  = null;
                        $current_heading_id = null;
                    }
                } else {
                    $current_heading_id = $tag;
                    $current_heading    = array(
                        'level' => (int) substr( $tag, 1 ),
                        'text'  => '',
                    );
                }
            }

            continue;
        }

        if ( null !== $current_heading && '#text' === $token_type ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( null !== $current_heading ) {
        $toc[] = $current_heading;
    }

    return $toc;
}
