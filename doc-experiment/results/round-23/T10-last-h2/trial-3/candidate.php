<?php
function mark_last_h2( string $html ): string {
    $processor = new WP_HTML_Tag_Processor( $html );

    if ( ! $processor->next_tag( 'H2' ) ) {
        return $html;
    }

    $processor->set_bookmark( 'last-h2' );

    while ( $processor->next_tag( 'H2' ) ) {
        $processor->set_bookmark( 'last-h2' );
    }

    if ( $processor->seek( 'last-h2' ) ) {
        $processor->add_class( 'final-section' );
    }

    $processor->release_bookmark( 'last-h2' );

    return $processor->get_updated_html();
}
