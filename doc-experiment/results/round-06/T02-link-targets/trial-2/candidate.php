<?php

function add_link_targets( string $html ): string {
    $processor = new WP_HTML_Tag_Processor( $html );
    
    while ( $processor->next_tag( 'A' ) ) {
        // Only process if the tag has an href attribute
        $href = $processor->get_attribute( 'href' );
        if ( $href !== null ) {
            // Set target to _blank (this will overwrite any existing target)
            $processor->set_attribute( 'target', '_blank' );
        }
    }
    
    return $processor->get_updated_html();
}
