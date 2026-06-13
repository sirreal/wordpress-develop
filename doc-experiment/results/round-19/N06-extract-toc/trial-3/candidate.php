<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    $toc           = array();
    $current_index = null;
    $current_tag   = null;

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null === $current_index ) {
            if ( null !== $token_name && '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
                if ( preg_match( '/^H([1-6])$/', $token_name, $matches ) ) {
                    $current_tag   = $token_name;
                    $current_index = count( $toc );
                    $toc[]         = array(
                        'level' => (int) $matches[1],
                        'text'  => '',
                    );
                }
            }

            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $toc[ $current_index ]['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && $processor->is_tag_closer() && $token_name === $current_tag ) {
            $current_index = null;
            $current_tag   = null;
        }
    }

    return $toc;
}
