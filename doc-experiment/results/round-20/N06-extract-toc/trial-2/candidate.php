<?php

function extract_toc( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );

    if ( null === $processor ) {
        return array();
    }

    $toc             = array();
    $current_heading = null;

    while ( $processor->next_token() ) {
        $token_name = $processor->get_token_name();

        if ( null === $token_name ) {
            continue;
        }

        $is_heading = 1 === preg_match( '/^H([1-6])$/', $token_name, $matches );

        if ( $is_heading ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_heading && $current_heading['level'] === (int) $matches[1] ) {
                    $toc[]           = $current_heading;
                    $current_heading = null;
                }
            } else {
                $current_heading = array(
                    'level' => (int) $matches[1],
                    'text'  => '',
                );
            }

            continue;
        }

        if ( null === $current_heading ) {
            continue;
        }

        if ( '#text' === $processor->get_token_type() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
            continue;
        }

        if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
            $current_heading['text'] .= $processor->get_modifiable_text();
        }
    }

    return $toc;
}
