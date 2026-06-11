<?php

function collect_html_img_sources( string $html ): array {
    // Create a Tag Processor to scan through the HTML
    $processor = new WP_HTML_Tag_Processor( $html );
    
    $sources = array();
    
    // Iterate through all IMG tags in the document
    while ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
        // Only collect IMG tags that are in the HTML namespace
        // SVG <image> elements are in the 'svg' namespace, not 'html'
        if ( 'html' !== $processor->get_namespace() ) {
            continue;
        }
        
        // Get the src attribute value
        $src = $processor->get_attribute( 'src' );
        
        // Skip if src is null (attribute not present) or if it's a boolean true (no value)
        // The get_attribute() method returns decoded values, so we use them directly
        if ( null !== $src && true !== $src && '' !== $src ) {
            $sources[] = $src;
        }
    }
    
    return $sources;
}
