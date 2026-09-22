<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc                  = array();
    $current_heading      = null;
    $current_heading_text = '';

    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $tag_name   = $processor->get_tag();

        if ( '#tag' === $token_type && null !== $tag_name ) {
            if ( ! $processor->is_tag_closer() && preg_match( '/^H([1-6])$/', $tag_name, $matches ) ) {
                $current_heading      = $tag_name;
                $current_heading_text = '';
                continue;
            }

            if ( null !== $current_heading && $processor->is_tag_closer() && $tag_name === $current_heading ) {
                $toc[] = array(
                    'level' => (int) substr( $current_heading, 1 ),
                    'text'  => $current_heading_text,
                );

                $current_heading      = null;
                $current_heading_text = '';
                continue;
            }
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $token_type ) {
            $current_heading_text .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $current_heading_text .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
