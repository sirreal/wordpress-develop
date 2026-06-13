<?php

function get_document_title( string $html ): ?string {
    $processor = new WP_HTML_Tag_Processor( $html );

    if ( ! $processor->next_tag( 'TITLE' ) ) {
        return null;
    }

    return $processor->get_modifiable_text();
}
