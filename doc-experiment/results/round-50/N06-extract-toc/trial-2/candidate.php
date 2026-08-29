<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc          = array();
    $heading_tags = array(
        'H1' => 1,
        'H2' => 2,
        'H3' => 3,
        'H4' => 4,
        'H5' => 5,
        'H6' => 6,
    );
    $current      = null;

    while ( $processor->next_token() ) {
        $tag = $processor->get_tag();

        if ( null !== $current ) {
            if (
                '#tag' === $processor->get_token_type() &&
                $processor->is_tag_closer() &&
                $tag === $current['tag']
            ) {
                $toc[]   = array(
                    'level' => $current['level'],
                    'text'  => $current['text'],
                );
                $current = null;
                continue;
            }

            if ( '#text' === $processor->get_token_type() ) {
                $current['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if (
            '#tag' === $processor->get_token_type() &&
            ! $processor->is_tag_closer() &&
            null !== $tag &&
            isset( $heading_tags[ $tag ] )
        ) {
            $current = array(
                'tag'   => $tag,
                'level' => $heading_tags[ $tag ],
                'text'  => '',
            );
        }
    }

    if ( null !== $current ) {
        $toc[] = array(
            'level' => $current['level'],
            'text'  => $current['text'],
        );
    }

    return $toc;
}
