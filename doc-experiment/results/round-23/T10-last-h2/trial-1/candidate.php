<?php
function mark_last_h2( string $html ): string {
    $processor = new WP_HTML_Tag_Processor( $html );
    $found_h2  = false;

    while ( $processor->next_tag( 'H2' ) ) {
        $processor->set_bookmark( 'last-h2' );
        $found_h2 = true;
    }

    if ( ! $found_h2 ) {
        return $html;
    }

    if ( ! $processor->seek( 'last-h2' ) ) {
        return $html;
    }

    $processor->add_class( 'final-section' );
    $processor->release_bookmark( 'last-h2' );

    return $processor->get_updated_html();
}
