<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_index   = null;
    $current_tag     = null;
    $heading_depth   = null;
    $heading_levels  = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( null !== $current_index ) {
            if ( '#text' === $token_type && $processor->get_current_depth() >= $heading_depth ) {
                $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
                continue;
            }

            if ( '#tag' === $token_type && $processor->is_tag_closer() && $token_name === $current_tag ) {
                $current_index = null;
                $current_tag   = null;
                $heading_depth = null;
                continue;
            }
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() || ! isset( $heading_levels[ $token_name ] ) ) {
            continue;
        }

        $toc[] = array(
            'level' => $heading_levels[ $token_name ],
            'text'  => '',
        );

        $current_index = count( $toc ) - 1;
        $current_tag   = $token_name;
        $heading_depth = $processor->get_current_depth();
    }

    return $toc;
}
