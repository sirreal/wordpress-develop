<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_index = null;
    $heading_depth = 0;

    while ( $processor->next_token() ) {
        if ( null !== $current_index && $processor->get_current_depth() < $heading_depth ) {
            $current_index = null;
            $heading_depth = 0;
        }

        if ( null !== $current_index ) {
            if ( '#text' === $processor->get_token_type() ) {
                $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag || 1 !== preg_match( '/^H([1-6])$/', $tag, $matches ) ) {
            continue;
        }

        $toc[] = array(
            'level' => (int) $matches[1],
            'text'  => '',
        );

        $current_index = count( $toc ) - 1;
        $heading_depth = $processor->get_current_depth();
    }

    return $toc;
}
