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

    $raw_text_tags = array(
        'IFRAME'   => true,
        'NOEMBED'  => true,
        'NOFRAMES' => true,
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
        'XMP'      => true,
    );

    $toc                 = array();
    $current_heading_tag = null;
    $current_index       = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag = $processor->get_tag();

            if ( null === $tag ) {
                continue;
            }

            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading_tag && $tag === $current_heading_tag ) {
                    $current_heading_tag = null;
                    $current_index       = null;
                }

                continue;
            }

            if ( isset( $heading_levels[ $tag ] ) ) {
                $toc[] = array(
                    'level' => $heading_levels[ $tag ],
                    'text'  => '',
                );

                $current_heading_tag = $tag;
                $current_index       = count( $toc ) - 1;
                continue;
            }

            if ( null !== $current_index && isset( $raw_text_tags[ $tag ] ) ) {
                $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_index && '#text' === $token_type ) {
            $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
        }
    }

    if ( $processor->paused_at_incomplete_token() || null !== $processor->get_last_error() ) {
        return array();
    }

    return $toc;
}
