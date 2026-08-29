<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc                 = array();
    $current_heading_tag = null;
    $current_heading_key = null;
    $text_only_tags      = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE' );

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();

        if ( '#tag' === $token_type ) {
            $tag_name = $processor->get_tag();

            if ( null !== $current_heading_key && $processor->is_tag_closer() && $tag_name === $current_heading_tag ) {
                $current_heading_tag = null;
                $current_heading_key = null;
                continue;
            }

            if ( ! $processor->is_tag_closer() && null !== $tag_name && in_array( $tag_name, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), true ) ) {
                $toc[] = array(
                    'level' => (int) substr( $tag_name, 1 ),
                    'text'  => '',
                );

                $current_heading_key = count( $toc ) - 1;
                $current_heading_tag = $tag_name;
                continue;
            }

            if ( null !== $current_heading_key && ! $processor->is_tag_closer() && in_array( $tag_name, $text_only_tags, true ) ) {
                $toc[ $current_heading_key ]['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_heading_key && '#text' === $token_type ) {
            $toc[ $current_heading_key ]['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
