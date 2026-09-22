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
        $last_index = count( $breadcrumbs ) - 1;

        for ( $i = 0; $i < $last_index; $i++ ) {
            if ( 'FIGURE' === $breadcrumbs[ $i ] ) {
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

    return $images;
}
