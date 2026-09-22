<?php

function get_document_title( string $html ): ?string {
    $processor = WP_HTML_Processor::create_full_parser( $html );

    if ( null === $processor ) {
        return null;
    }

    if ( ! $processor->next_tag( 'TITLE' ) ) {
        return null;
    }

    return $processor->get_modifiable_text();
}
