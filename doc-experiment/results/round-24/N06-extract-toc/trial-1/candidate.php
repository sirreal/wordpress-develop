<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc                  = array();
    $current_heading_index = null;
    $heading_tags         = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null !== $token_name && in_array( $token_name, $heading_tags, true ) ) {
            if ( $processor->is_tag_closer() ) {
                $current_heading_index = null;
            } else {
                $toc[] = array(
                    'level' => (int) substr( $token_name, 1 ),
                    'text'  => '',
                );
                $current_heading_index = count( $toc ) - 1;
            }

            continue;
        }

        if ( null !== $current_heading_index && '#text' === $processor->get_token_type() ) {
            $toc[ $current_heading_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
