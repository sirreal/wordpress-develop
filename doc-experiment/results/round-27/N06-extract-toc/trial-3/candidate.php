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

    $toc                 = array();
    $current_heading_tag = null;
    $current_text        = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null === $tag ) {
                continue;
            }

            if ( isset( $heading_levels[ $tag ] ) ) {
                if ( $processor->is_tag_closer() ) {
                    if ( $current_heading_tag === $tag ) {
                        $toc[] = array(
                            'level' => $heading_levels[ $tag ],
                            'text'  => $current_text,
                        );

                        $current_heading_tag = null;
                        $current_text        = '';
                    }
                } else {
                    $current_heading_tag = $tag;
                    $current_text        = '';
                }

                continue;
            }

            if (
                null !== $current_heading_tag &&
                ! $processor->is_tag_closer() &&
                (
                    'SCRIPT' === $tag ||
                    'STYLE' === $tag ||
                    'TEXTAREA' === $tag ||
                    'TITLE' === $tag
                )
            ) {
                $current_text .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_heading_tag && '#text' === $token_type ) {
            $current_text .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
