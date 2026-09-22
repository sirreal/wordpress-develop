<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $images         = array();
    $figure_nesting = 0;

    while ( $processor->next_token() ) {
        if ( '#tag' !== $processor->get_token_type() ) {
            continue;
        }

        $tag_name = $processor->get_tag();

        if ( 'FIGURE' === $tag_name ) {
            if ( $processor->is_tag_closer() ) {
                if ( $figure_nesting > 0 ) {
                    $figure_nesting--;
                }
            } else {
                $figure_nesting++;
            }

            continue;
        }

        if ( 'IMG' !== $tag_name || $processor->is_tag_closer() || $figure_nesting < 1 ) {
            continue;
        }

        $src = $processor->get_attribute( 'src' );
        if ( is_string( $src ) && '' !== $src ) {
            $images[] = $src;
        }
    }

    return $images;
}
