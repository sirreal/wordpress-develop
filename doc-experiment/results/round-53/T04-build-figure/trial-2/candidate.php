<?php

function build_figure( string $url, string $alt, string $caption ): string {
    $html = '<figure><img src="" alt=""><figcaption>.</figcaption></figure>';

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( ! $processor ) {
        return '';
    }

    while ( $processor->next_token() ) {
        if ( '#tag' === $processor->get_token_type() && 'IMG' === $processor->get_tag() && ! $processor->is_tag_closer() ) {
            $processor->set_attribute( 'src', $url );
            $processor->set_attribute( 'alt', $alt );
            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $processor->set_modifiable_text( $caption );
            break;
        }
    }

    return $processor->get_updated_html();
}
