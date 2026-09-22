<?php

function collect_html_img_sources( string $html ): array {
    $sources = array();
    
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( $processor === null ) {
        return $sources;
    }
    
    while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
        // Skip SVG image elements - only process HTML namespace img tags
        if ( 'html' !== $processor->get_namespace() ) {
            continue;
        }
        
        // Get the src attribute value
        $src = $processor->get_attribute( 'src' );
        
        // Include only if src exists and is not empty
        if ( $src !== null && $src !== '' && $src !== true ) {
            $sources[] = $src;
        }
    }
    
    return $sources;
}
