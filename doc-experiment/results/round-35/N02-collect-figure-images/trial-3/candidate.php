<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $images = array();

    while ( $processor->next_tag( 'IMG' ) ) {
        $breadcrumbs = $processor->get_breadcrumbs();
        $inside_figure = false;

        foreach ( $breadcrumbs as $breadcrumb ) {
            if ( 'IMG' === $breadcrumb ) {
                break;
            }

            if ( 'FIGURE' === $breadcrumb ) {
                $inside_figure = true;
                break;
            }
        }

        if ( ! $inside_figure ) {
            continue;
        }

        $src = $processor->get_attribute( 'src' );

        if ( is_string( $src ) && '' !== $src ) {
            $images[] = $src;
        }
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $images;
}
