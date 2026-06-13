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

    $toc     = array();
    $current = null;

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( null !== $current ) {
            if ( '#text' === $token_type ) {
                $current['text'] .= $processor->get_modifiable_text();
            } elseif ( '#tag' === $token_type ) {
                $tag = $processor->get_tag();

                if ( $processor->is_tag_closer() ) {
                    if ( $tag === $current['tag'] ) {
                        $toc[] = array(
                            'level' => $current['level'],
                            'text'  => $current['text'],
                        );
                        $current = null;
                    }
                } else {
                    $current['text'] .= $processor->get_modifiable_text();
                }
            }
        }

        if ( '#tag' !== $token_type || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || ! isset( $heading_levels[ $tag ] ) ) {
            continue;
        }

        $current = array(
            'tag'   => $tag,
            'level' => $heading_levels[ $tag ],
            'text'  => '',
        );
    }

    return $toc;
}
