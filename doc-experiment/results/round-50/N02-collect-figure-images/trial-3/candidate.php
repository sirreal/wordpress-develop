<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $images = array();

    while ( $processor->next_tag( array( 'breadcrumbs' => array( 'FIGURE', 'IMG' ) ) ) ) {
        $src = $processor->get_attribute( 'src' );

        if ( is_string( $src ) && '' !== $src ) {
            $images[] = $src;
        }
    }

    return $images;
}
