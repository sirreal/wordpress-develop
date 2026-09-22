<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $images       = array();
    $figure_depth = 0;

    while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
        if ( 'FIGURE' === $processor->get_tag() ) {
            if ( $processor->is_tag_closer() ) {
                if ( $figure_depth > 0 ) {
                    $figure_depth--;
                }
            } else {
                $figure_depth++;
            }

            continue;
        }

        if ( $figure_depth > 0 && 'IMG' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $src = $processor->get_attribute( 'src' );

            if ( is_string( $src ) && '' !== $src ) {
                $images[] = $src;
            }
        }
    }

    return $images;
}
