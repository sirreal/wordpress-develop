<?php

function collect_links( string $html ): array {
    if ( ! class_exists( 'WP_HTML_Processor' ) ) {
        return array();
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $links = array();

    $current_href  = null;
    $current_text  = '';
    $current_depth = null;

    while ( $processor->next_token() ) {
        $token_depth = $processor->get_current_depth();

        if ( null !== $current_href && $token_depth < $current_depth ) {
            $links[] = array(
                'href' => $current_href,
                'text' => $current_text,
            );

            $current_href  = null;
            $current_text  = '';
            $current_depth = null;
        }

        if ( null !== $current_href ) {
            if ( '#text' === $processor->get_token_type() ) {
                $current_text .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( '#tag' !== $processor->get_token_type() ) {
            continue;
        }

        if ( $processor->is_tag_closer() || 'A' !== $processor->get_tag() ) {
            continue;
        }

        $href = $processor->get_attribute( 'href' );
        if ( ! is_string( $href ) ) {
            continue;
        }

        $current_href  = $href;
        $current_text  = '';
        $current_depth = $token_depth;
    }

    if ( null !== $current_href ) {
        $links[] = array(
            'href' => $current_href,
            'text' => $current_text,
        );
    }

    return $links;
}
