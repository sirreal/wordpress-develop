<?php
function build_figure( string $url, string $alt, string $caption ): string {
    // Use a template with the figure structure and empty attribute values
    $processor = new WP_HTML_Tag_Processor( '<figure><img src="" alt=""><figcaption>.</figcaption></figure>' );
    
    // Find and set the img tag attributes
    if ( $processor->next_tag( array( 'tag_name' => 'img' ) ) ) {
        $processor->set_attribute( 'src', $url );
        $processor->set_attribute( 'alt', $alt );
    }
    
    // Find the figcaption text node and replace it
    while ( $processor->next_token() ) {
        if ( '#text' === $processor->get_token_type() ) {
            $processor->set_modifiable_text( $caption );
            break;
        }
    }
    
    return $processor->get_updated_html();
}
