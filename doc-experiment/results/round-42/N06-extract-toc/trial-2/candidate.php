<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_level   = null;
    $current_tag     = null;
    $current_text    = '';
    $heading_tag_set = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() ) {
            $tag = $processor->get_tag();

            if ( null !== $current_tag && $processor->is_tag_closer() && $tag === $current_tag ) {
                $toc[] = array(
                    'level' => $current_level,
                    'text'  => $current_text,
                );

                $current_level = null;
                $current_tag   = null;
                $current_text  = '';
                continue;
            }

            if ( ! $processor->is_tag_closer() && null === $current_tag && isset( $heading_tag_set[ $tag ] ) ) {
                $current_tag   = $tag;
                $current_level = $heading_tag_set[ $tag ];
                $current_text  = '';
            }

            continue;
        }

        if ( null !== $current_tag && '#text' === $processor->get_token_type() ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
