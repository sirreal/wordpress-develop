<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $heading_levels = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null !== $tag && isset( $heading_levels[ $tag ] ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( null !== $current_heading && $current_heading['tag'] === $tag ) {
                        $toc[] = array(
                            'level' => $current_heading['level'],
                            'text'  => $current_heading['text'],
                        );
                        $current_heading = null;
                    }
                } else {
                    $current_heading = array(
                        'tag'   => $tag,
                        'level' => $heading_levels[ $tag ],
                        'text'  => '',
                    );
                }

                continue;
            }
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $token_type ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
