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

            if ( $processor->is_tag_closer() ) {
                continue;
            }

            $tag_name = $processor->get_tag();
            if (
                'SCRIPT' === $tag_name ||
                'STYLE' === $tag_name ||
                'TITLE' === $tag_name ||
                'TEXTAREA' === $tag_name
            ) {
                $text .= $processor->get_modifiable_text();
            }
        }

        $links[] = array(
            'href' => $href,
            'text' => $text,
        );
    }

    return $links;
}
