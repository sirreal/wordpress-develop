<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc          = array();
    $current_tag  = null;
    $current_level = 0;
    $current_text = '';
    $heading_tags = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    while ( $processor->next_token() ) {
        $tag = $processor->get_tag();

        if ( null !== $current_tag && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
            continue;
        }

        if ( null === $tag || ! isset( $heading_tags[ $tag ] ) ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( $tag === $current_tag ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );

                $current_tag   = null;
                $current_level = 0;
                $current_text  = '';
            }

            continue;
        }

        $current_tag   = $tag;
        $current_level = $heading_tags[ $tag ];
        $current_text  = '';
    }

    return $toc;
}
