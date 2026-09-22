<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $images = array();

    while ( $processor->next_tag( 'IMG' ) ) {
        if ( ! in_array( 'FIGURE', $processor->get_breadcrumbs(), true ) ) {
            continue;
        }

        $src = $processor->get_attribute( 'src' );
        if ( ! is_string( $src ) || '' === $src ) {
            continue;
        }

        $images[] = $src;
    }

    if ( null !== $processor->get_last_error() ) {
        return array();
    }

    return $images;
}
