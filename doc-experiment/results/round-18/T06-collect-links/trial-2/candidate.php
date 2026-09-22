<?php

function collect_links( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $links = array();

    while ( $processor->next_tag( 'A' ) ) {
        $href = $processor->get_attribute( 'href' );
        if ( ! is_string( $href ) ) {
            continue;
        }

        $text       = '';
        $link_depth = $processor->get_current_depth();

        while ( $processor->next_token() && $processor->get_current_depth() >= $link_depth ) {
            if ( '#text' === $processor->get_token_type() ) {
                $text .= $processor->get_modifiable_text();
                continue;
            }

            if ( ! $processor->is_tag_closer() ) {
                $token_name = $processor->get_token_name();
                if ( 'SCRIPT' === $token_name || 'STYLE' === $token_name || 'TEXTAREA' === $token_name || 'TITLE' === $token_name ) {
                    $text .= $processor->get_modifiable_text();
                }
            }
        }

        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    return $links;
}
