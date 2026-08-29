<?php

function collect_figure_images( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( null === $processor ) {
        return array();
    }
    
    $images = array();
    
    while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
        $breadcrumbs = $processor->get_breadcrumbs();
        
        // Check if FIGURE appears in the breadcrumbs before IMG
        $has_figure_ancestor = in_array( 'FIGURE', $breadcrumbs, true );
        
        if ( $has_figure_ancestor ) {
            $src = $processor->get_attribute( 'src' );
            
            // Only include if src exists and is not empty string
            if ( is_string( $src ) && '' !== $src ) {
                $images[] = $src;
            }
        }
    }
    
    return $images;
}
